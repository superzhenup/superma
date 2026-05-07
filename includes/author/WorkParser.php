<?php
/**
 * 作品解析器 - 作者画像系统
 * 负责解析上传的小说文件（ TXT、DOCX 等格式）
 */

require_once __DIR__ . '/TextProcessor.php';

class WorkParser
{
    private const ALLOWED_TYPES = ['txt', 'docx', 'doc'];
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;

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
        return match ($extension) {
            'txt' => $this->parseTxt($filePath),
            'docx' => $this->parseDocx($filePath),
            'doc' => $this->parseDoc($filePath),
            default => false,
        };
    }

    private function parseTxt(string $filePath): string|false
    {
        $content = @file_get_contents($filePath);
        if ($content === false) return false;

        $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        return $content ?: false;
    }

    private function parseDocx(string $filePath): string|false
    {
        if (!file_exists('zip://' . $filePath . '#word/document.xml')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return false;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) return false;

        $xml = preg_replace('/<[^>]+>/', ' ', $xml);
        $xml = html_entity_decode($xml, ENT_XML1, 'UTF-8');

        $content = preg_replace('/\s+/', ' ', $xml);
        $content = trim($content);

        return $content ?: false;
    }

    private function parseDoc(string $filePath): string|false
    {
        // 检查 catdoc 是否可用
        $catdocAvailable = (stripos(PHP_OS, 'WIN') === false) && @shell_exec('which catdoc 2>/dev/null');

        if ($catdocAvailable) {
            $content = @shell_exec('catdoc ' . escapeshellarg($filePath) . ' 2>/dev/null');
            if ($content !== null && trim($content) !== '') {
                return $content;
            }
        }

        // 回退方案1：尝试使用 antiword（另一个doc解析工具）
        $antiwordAvailable = (stripos(PHP_OS, 'WIN') === false) && @shell_exec('which antiword 2>/dev/null');
        if ($antiwordAvailable) {
            $content = @shell_exec('antiword ' . escapeshellarg($filePath) . ' 2>/dev/null');
            if ($content !== null && trim($content) !== '') {
                return $content;
            }
        }

        // 回退方案2：尝试读取为纯文本（可能包含乱码，但总比没有好）
        $content = @file_get_contents($filePath);
        if ($content !== false) {
            // 尝试去除二进制控制字符
            $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);
            if (trim($content) !== '') {
                return $content;
            }
        }

        return false;
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
