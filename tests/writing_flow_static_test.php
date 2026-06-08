<?php
/**
 * Static regression checks for the writing flow.
 *
 * These tests intentionally avoid a live DB/API key. They guard the control-flow
 * contracts that caused production risk in the writing pipeline.
 */

$root = dirname(__DIR__);

function read_source(string $relativePath): string
{
    global $root;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($path)) {
        throw new RuntimeException("Missing source file: {$relativePath}");
    }
    return file_get_contents($path);
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    assert_true(strpos($haystack, $needle) !== false, $message);
}

function assert_not_contains(string $needle, string $haystack, string $message): void
{
    assert_true(strpos($haystack, $needle) === false, $message);
}

$engine = read_source('includes/write_engine.php');
$sse = read_source('api/write_chapter.php');
$worker = read_source('api/write_chapter_worker.php');
$daemon = read_source('api/daemon_write.php');
$chapterPage = read_source('chapter.php');
$novelPage = read_source('novel.php');
$appJs = read_source('assets/js/app.js');
$styleCss = read_source('assets/css/style.css');

// P0: strict constraint violations must remain distinguishable from persistence failures.
assert_contains('class WriteEngineValidationException', $engine, 'WriteEngine must define a validation exception type.');
assert_contains('class WriteEnginePersistenceException', $engine, 'WriteEngine must define a persistence exception type.');
assert_contains('throw new WriteEngineValidationException', $engine, 'Strict P0 validation must throw the validation exception.');
assert_contains('throw new WriteEnginePersistenceException', $engine, 'Blocked persistence must throw the persistence exception.');
assert_contains('catch (WriteEngineValidationException $e)', $sse, 'SSE entry must not fallback-save strict validation failures.');
assert_contains('catch (WriteEngineValidationException $e)', $worker, 'Worker entry must not fallback-save strict validation failures.');
assert_contains('catch (WriteEnginePersistenceException $e)', $sse, 'SSE entry must fallback-save only persistence failures.');
assert_contains('catch (WriteEnginePersistenceException $e)', $worker, 'Worker entry must fallback-save only persistence failures.');

// P1: async worker must pass model context into memory initialization so 1M models can use full context.
assert_contains('$preAiClient = getAIClient', $worker, 'Worker must create a preflight AI client.');
assert_contains('WriteEngine::initMemory($novelId, $ch, $preAiClient)', $worker, 'Worker must pass preflight AI client to initMemory.');

// P1: daemon writing must reuse the same engine phases as interactive writing.
assert_contains('WriteEngine::initMemory($novelId, $ch, $preAiClient)', $daemon, 'Daemon must use WriteEngine memory initialization.');
assert_contains('WriteEngine::streamWrite(', $daemon, 'Daemon must use WriteEngine stream writing.');
assert_contains('WriteEngine::saveChapter(', $daemon, 'Daemon must use WriteEngine save logic.');
assert_contains('catch (WriteEngineValidationException $e)', $daemon, 'Daemon must not retry strict validation failures as transient write errors.');
assert_not_contains('new AIClient($modelCfg)', $daemon, 'Daemon must not duplicate AIClient streaming logic.');
assert_not_contains('->chatStream(', $daemon, 'Daemon must not call chatStream directly.');

// P1: chapter page should use the unified async-first writing client instead of direct SSE fetch.
assert_true(
    (bool)preg_match('/streamWriteChapter\s*\(\s*NOVEL_ID\s*,\s*CHAPTER_ID/s', $chapterPage),
    'Chapter page rewrite button must use unified writing client.'
);
assert_not_contains("fetch('api/write_chapter.php'", $chapterPage, 'Chapter page must not direct-fetch write_chapter.php.');
assert_contains('function apiRouteUrl(', $appJs, 'Writing frontend must build API route URLs through a subdirectory-safe helper.');
assert_not_contains("fetch('/api/index.php?route=' + encodeURIComponent(url)", $appJs, 'apiPost route calls must not hard-code the web root; subdirectory deploys lose write_start output.');
assert_not_contains("fetch('/api/index.php?route=write_chapter'", $appJs, 'SSE write fallback must not hard-code the web root; subdirectory deploys lose streamed content.');

// P1: intelligent creative assist system must expose a focused page, API, service, and frontend controller.
assert_contains('assist.php?id=', $novelPage, 'Novel page must link to the intelligent creative assist system.');
$assistPage = read_source('assist.php');
$assistApi = read_source('api/creative_assist.php');
$assistService = read_source('includes/CreativeAssistService.php');
$assistJs = read_source('assets/js/assist.js');

assert_contains('智能创作辅助系统', $assistPage, 'Assist page must use the approved product name.');
assert_contains('assets/js/assist.js', $assistPage, 'Assist page must load its JavaScript controller.');
assert_contains("case 'context'", $assistApi, 'Assist API must expose context action.');
assert_contains("case 'save_directive'", $assistApi, 'Assist API must expose temporary directive action.');
assert_contains("case 'quality'", $assistApi, 'Assist API must expose quality action.');
assert_contains('class CreativeAssistService', $assistService, 'Assist service class must exist.');
assert_contains('buildContext', $assistService, 'Assist service must build the context payload.');
assert_contains('CreativeAssist', $assistJs, 'Assist frontend controller must exist.');

// P1: story outline text must stay readable in dark mode.
assert_contains('[data-theme="dark"] #story-outline-content', $styleCss, 'Story outline content needs an explicit dark-mode color rule.');
assert_contains('color: #fff !important', $styleCss, 'Story outline content must render as white in dark mode.');

// P1: novel chapter pagination must be AJAX-refreshable without loading the full page.
$chapterListPartial = read_source('includes/chapter_list_partial.php');
$chapterListApi = read_source('api/chapter_list.php');
$appJs = read_source('assets/js/app.js');

assert_contains("include __DIR__ . '/includes/chapter_list_partial.php'", $novelPage, 'Novel page must render chapters through the shared partial.');
assert_contains('chapter-list-ajax-root', $chapterListPartial, 'Chapter list partial must expose an AJAX replacement root.');
assert_contains('data-ajax-page', $chapterListPartial, 'Chapter list pagination links must expose AJAX page numbers.');
assert_contains('novel_id', $chapterListApi, 'Chapter list API must accept novel_id.');
assert_contains('chapter_list_partial.php', $chapterListApi, 'Chapter list API must render the shared partial.');
assert_contains('function bindChapterListEvents', $appJs, 'Chapter list JS must centralize event binding.');
assert_contains('async function loadChapterList', $appJs, 'Chapter list JS must expose AJAX loader.');
assert_contains('history.pushState', $appJs, 'Chapter list AJAX pagination must update browser history.');

// P1: chapter detail "view" modal must hit a real JSON endpoint instead of receiving an HTML error page.
$chapterActionsApi = read_source('api/chapter_actions.php');
assert_contains('btn-chapter-detail', $chapterListPartial, 'Chapter list must expose the detail modal trigger.');
assert_contains('api/chapter_actions.php', $appJs, 'Chapter detail JS must call the chapter actions endpoint.');
assert_contains("case 'get_detail'", $chapterActionsApi, 'Chapter actions API must support loading detail modal data.');
assert_contains('synopsis', $chapterActionsApi, 'Chapter detail payload must include chapter synopsis text.');
assert_contains('word_count', $chapterActionsApi, 'Chapter detail payload must expose word_count for the modal.');

// P1: chapter title optimization must suggest a better title from the current outline fields without auto-saving.
$titleOptimizeApi = read_source('api/optimize_chapter_title.php');
assert_contains('btn-optimize-title', $chapterPage, 'Chapter edit title area must expose an AI title optimization button.');
assert_contains('function optimizeChapterTitle', $chapterPage, 'Chapter page must define the title optimization client.');
assert_contains("fetch('api/optimize_chapter_title.php'", $chapterPage, 'Chapter page must call the dedicated title optimization API.');
assert_contains('current_title', $titleOptimizeApi, 'Title optimization API must consider the original/current title.');
assert_contains('outline', $titleOptimizeApi, 'Title optimization API must consider the chapter outline.');
assert_contains('key_points', $titleOptimizeApi, 'Title optimization API must consider key plot points.');
assert_contains('hook', $titleOptimizeApi, 'Title optimization API must consider the ending hook.');
assert_contains('getModelFallbackList', $titleOptimizeApi, 'Title optimization API must use configured models via the fallback list (not one fixed model — a slow/wrong single model must not sink the feature).');
assert_contains('register_shutdown_function', $titleOptimizeApi, 'Title optimization must always return JSON, even on a fatal/timeout (avoids empty body → "Unexpected end of JSON input").');
assert_not_contains("DB::update('chapters'", $titleOptimizeApi, 'Title optimization API must not auto-save chapter titles.');

// P1: outline generation and optimization must share a deterministic quality guard before saving.
$outlineGuard = read_source('includes/OutlineQualityGuard.php');
$generateOutlineApi = read_source('api/generate_outline.php');
$optimizeOutlineApi = read_source('api/optimize_outline.php');
$optimizeOutlineAjaxApi = read_source('api/optimize_outline_ajax.php');
$optimizeOutlineAjaxJs = read_source('assets/js/optimize_outline_ajax.js');
$promptSource = read_source('includes/prompt.php');

assert_contains('class OutlineQualityGuard', $outlineGuard, 'Outline quality guard service must exist.');
assert_contains('function evaluate', $outlineGuard, 'Outline quality guard must evaluate outline batches.');
assert_contains('function buildRepairMessages', $outlineGuard, 'Outline quality guard must build repair prompts.');
assert_contains('function mergeRepairedOutlines', $outlineGuard, 'Outline quality guard must merge local repairs.');
assert_contains('OutlineQualityGuard.php', $generateOutlineApi, 'Outline generation must load the shared quality guard.');
assert_contains('repairOutlineBatchWithGuard', $generateOutlineApi, 'Outline generation must repair failed quality checks before saving.');
assert_contains('OutlineQualityGuard.php', $optimizeOutlineApi, 'SSE outline optimization must load the shared quality guard.');
assert_contains('OutlineQualityGuard.php', $optimizeOutlineAjaxApi, 'AJAX outline optimization must load the shared quality guard.');
assert_contains("case 'quality_issue'", $appJs, 'SSE outline optimization UI must display quality guard issues.');
assert_contains('quality_issues', $optimizeOutlineAjaxApi, 'AJAX outline optimization response must expose quality guard issues.');
assert_contains('window.optimizeOutline', $appJs, 'Novel page optimize button must use the resilient unified optimize entry.');
assert_contains('function fetchLastOptimized', $optimizeOutlineAjaxJs, 'AJAX outline optimization must read resume progress before batching.');
assert_contains('headers: jsonHeaders()', $optimizeOutlineAjaxJs, 'AJAX outline optimization requests must include CSRF headers.');
foreach (['story_delta', 'conflict', 'result', 'next_trigger', 'unique_role'] as $field) {
    assert_contains($field, $promptSource, "Outline prompt must ask for {$field}.");
}

echo "writing_flow_static_test passed\n";
