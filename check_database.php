<?php
/**
 * 检查数据库表结构
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_LOADED', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "<h2>数据库表结构检查</h2>";

try {
    // 检查 novels 表
    echo "<h3>1. novels 表</h3>";
    $novels = DB::fetchAll('DESCRIBE novels');
    echo "<pre>" . print_r($novels, true) . "</pre>";
    
    // 检查 chapters 表
    echo "<h3>2. chapters 表</h3>";
    $chapters = DB::fetchAll('DESCRIBE chapters');
    echo "<pre>" . print_r($chapters, true) . "</pre>";
    
    // 检查 chapter_synopses 表是否存在
    echo "<h3>3. chapter_synopses 表</h3>";
    try {
        $synopses = DB::fetchAll('DESCRIBE chapter_synopses');
        echo "<pre>" . print_r($synopses, true) . "</pre>";
    } catch (Exception $e) {
        echo "<p style='color:red'>表不存在，需要创建！</p>";
        
        // 创建表
        echo "<h4>创建 chapter_synopses 表...</h4>";
        DB::query('
            CREATE TABLE `chapter_synopses` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `novel_id` int(11) NOT NULL COMMENT "小说ID",
              `chapter_number` int(11) NOT NULL COMMENT "章节编号",
              `synopsis` text COMMENT "章节概要",
              `pacing` varchar(10) DEFAULT "中" COMMENT "节奏：快/中/慢",
              `cliffhanger` text COMMENT "结尾悬念",
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `novel_chapter` (`novel_id`, `chapter_number`),
              KEY `novel_id` (`novel_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT="章节概要表"
        ');
        echo "<p style='color:green'>✓ 表创建成功！</p>";
    }
    
    // 检查小说数据
    echo "<h3>4. 小说数据</h3>";
    $novelList = DB::fetchAll('SELECT id, title, target_chapters FROM novels LIMIT 5');
    echo "<pre>" . print_r($novelList, true) . "</pre>";
    
    // 检查章节数据
    if (!empty($novelList)) {
        $firstNovel = $novelList[0];
        echo "<h3>5. 小说《{$firstNovel['title']}》的章节</h3>";
        $chapterList = DB::fetchAll('SELECT id, chapter_number, title FROM chapters WHERE novel_id = ? ORDER BY chapter_number LIMIT 10', [$firstNovel['id']]);
        echo "<pre>" . print_r($chapterList, true) . "</pre>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>错误: " . $e->getMessage() . "</p>";
}
