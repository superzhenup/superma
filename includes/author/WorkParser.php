<?php
/**
 * 作品解析器 - 作者画像系统
 * 负责解析上传的小说文件（ TXT、DOCX 等格式）
 */
defined('APP_LOADED') or die('Direct access denied.');

require_once __DIR__ . '/TextProcessor.php';

class WorkParser
{
    private const ALLOWED_TYPES = ['txt', 'docx', 'doc'];
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;
    private const MAX_DOCX_XML_SIZE = 20 * 1024 * 1024;
    private const MAX_EXTRACTED_TEXT_CHARS = 5000000;

    private string $uploadDir;

    public function __construct(?string $uploadDir = null)
    {
        $this->uploadDir = $uploadDir ?? dirname(__DIR__, 2) . '/storage/author_works';
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    public function parseUpload(array $file): array
    {
        $result = [
            'success' => false,
            'work_id' => null,
            'file_path' => null,
            'chapters' => [],
            'char_count' => 0,
            'chapter_count' => 0,
            'error' => null,
        ];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $result['error'] = $this->getUploadError($file['error']);
            return $result;
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_TYPES)) {
            $result['error'] = '不支持的文件格式，仅支持：' . implode(', ', self::ALLOWED_TYPES);
            return $result;
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            $result['error'] = '文件过大，最大支持 ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB';
            return $result;
        }

        $storedPath = $this->storeFile($file);
        if (!$storedPath) {
            $result['error'] = '文件保存失败';
            return $result;
        }
        $result['file_path'] = $storedPath;

        $content = $this->extractContent($storedPath, $extension);
        if ($content === false) {
            @unlink($storedPath);
            $result['file_path'] = null;
            $result['error'] = '文件解析失败';
            return $result;
        }

        $processor = new TextProcessor($content);
        $processor->clean()->normalize();
        $chapters = $processor->chunkByChapter(200);

        $result['success'] = true;
        $result['chapters'] = $chapters;
        $result['char_count'] = $processor->getCharCount();
        $result['chapter_count'] = count($chapters);

        return $result;
    }

    private function storeFile(array $file): ?string
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $newName = uniqid('work_', true) . '.' . $extension;
        $targetPath = $this->uploadDir . '/' . $newName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return null;
        }

        return $targetPath;
    }

    public function extractContent(string $filePath, string $extension): string|false
    {
        // 路径白名单校验：确保文件位于允许的上传目录内，防止路径遍历
        $realPath = realpath($filePath);
        $realUploadDir = realpath($this->uploadDir);
        if ($realPath === false || $realUploadDir === false || strpos($realPath, $realUploadDir . DIRECTORY_SEPARATOR) !== 0) {
            error_log("WorkParser::extractContent 路径校验失败: filePath={$filePath}");
            return false;
        }

        return match ($extension) {
            'txt' => $this->parseTxt($realPath),
            'docx' => $this->parseDocx($realPath),
            'doc' => $this->parseDoc($realPath),
            default => false,
        };
    }

    private function parseTxt(string $filePath): string|false
    {
        $content = @file_get_contents($filePath);
        if ($content === false) return false;

        $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
        if ($encoding === false) return false;
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        if (mb_strlen($content) > self::MAX_EXTRACTED_TEXT_CHARS) return false;

        return $content ?: false;
    }

    private function parseDocx(string $filePath): string|false
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return false;
        }

        $entry = $zip->statName('word/document.xml');
        if ($entry === false || (int)($entry['size'] ?? 0) > self::MAX_DOCX_XML_SIZE) {
            $zip->close();
            return false;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) return false;

        // Text runs can split a single word; paragraphs must remain separate
        // so chapter headings do not swallow all following prose.
        $document = new DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                return false;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        $namespace = $document->documentElement?->namespaceURI;
        if (!in_array($namespace, [
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
            'http://purl.oclc.org/ooxml/wordprocessingml/main',
        ], true)) return false;
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', $namespace);
        $paragraphs = [];
        foreach ($xpath->query('/w:document/w:body//w:p') as $paragraph) {
            $text = '';
            foreach ($xpath->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $paragraph) as $token) {
                $text .= match ($token->localName) {
                    't' => $token->textContent,
                    'tab' => "\t",
                    default => "\n",
                };
            }
            $paragraphs[] = $text;
        }
        $content = trim(implode("\n", $paragraphs));
        if (mb_strlen($content) > self::MAX_EXTRACTED_TEXT_CHARS) {
            return false;
        }

        return $content ?: false;
    }

    private function parseDoc(string $filePath): string|false
    {
        // 不依赖 shell_exec/which：宝塔常禁用 shell_exec，但允许更安全的 proc_open。
        // 命令以数组形式传递，文件名不会进入 shell 解析。
        foreach (['catdoc', 'antiword'] as $extractor) {
            $content = $this->runDocExtractor($extractor, $filePath);
            if ($content !== false && trim($content) !== '') {
                $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
                if ($encoding && $encoding !== 'UTF-8') {
                    $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                }
                if (mb_strlen($content) <= self::MAX_EXTRACTED_TEXT_CHARS) return $content;
                return false;
            }
        }

        // 仅对“扩展名为 .doc、实际内容是纯文本”的文件兜底。真正的 OLE .doc
        // 不能把二进制控制字符删除后冒充正文，否则会把乱码送入画像分析并污染数据。
        $content = @file_get_contents($filePath);
        if ($content === false || str_starts_with($content, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") || str_contains($content, "\0")) return false;
        $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
        if ($encoding === false) return false;
        if ($encoding !== 'UTF-8') $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        if (trim($content) !== '' && mb_strlen($content) <= self::MAX_EXTRACTED_TEXT_CHARS) return $content;

        return false;
    }

    private function runDocExtractor(string $binary, string $filePath): string|false
    {
        if (!function_exists('proc_open')) return false;
        $process = @proc_open(
            [$binary, $filePath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) return false;
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || $stdout === false || trim($stdout) === '') {
            if ($stderr !== false && $stderr !== '') {
                error_log('WorkParser ' . $binary . ' failed: ' . mb_substr(trim($stderr), 0, 300));
            }
            return false;
        }
        return $stdout;
    }

    public function parseText(string $text): array
    {
        $processor = new TextProcessor($text);
        $processor->clean()->normalize();
        $chapters = $processor->chunkByChapter(200);

        return [
            'success' => true,
            'chapters' => $chapters,
            'char_count' => $processor->getCharCount(),
            'chapter_count' => count($chapters),
        ];
    }

    public function getUploadDir(): string
    {
        return $this->uploadDir;
    }

    private function getUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => '文件大小超过服务器限制',
            UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
            UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
            UPLOAD_ERR_NO_FILE => '没有文件被上传',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            UPLOAD_ERR_EXTENSION => '文件上传被扩展程序阻止',
            default => '未知上传错误',
        };
    }

    public static function validateFileType(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, self::ALLOWED_TYPES);
    }

    public static function getAllowedExtensions(): array
    {
        return self::ALLOWED_TYPES;
    }
}
