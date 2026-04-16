<img width="1896" height="893" alt="image" src="https://github.com/user-attachments/assets/e1ee7730-5f69-49ec-8946-489e9a034169" />

# superma Super-Ma 是一个基于 PHP + MySQL 的 AI 驱动小说创作平台，让创作者借助 AI 轻松构建长篇小说。

✦ AI 小说创作系统
Super-Ma 是一个基于 PHP + MySQL 的 AI 驱动小说创作平台，支持自动生成章节大纲、流式写作、多模型配置，并接入火山方舟 Coding Plan 等主流 AI API，助力创作者轻松构建长篇小说。

目录
功能特性
环境要求
快速安装
目录结构
使用指南
登录系统
配置 AI 模型
新建小说
生成章节大纲
AI 自动写作
章节管理
支持的 AI 模型
常见问题
功能特性
功能	说明
🔐 登录验证	账号密码登录，Session 鉴权，全站接口保护
⚡ 一键安装	填写数据库信息和管理员账号，自动建库建表
📚 书库管理	多部小说并行管理，卡片式展示进度与状态
🗂 大纲生成	批量生成章节大纲（标题、摘要、关键情节、结尾钩子）
✍️ 流式写作	SSE 实时流式输出，写作进度可视化，支持中途取消
🔄 模型 Fallback	主模型失败时自动切换备用模型，保障写作连续性
🤖 多模型支持	兼容所有 OpenAI 协议 API，内置 13 种预设配置
🌙 双主题	暗色 / 亮色主题自由切换，偏好本地持久化
环境要求
组件	版本要求
PHP	≥ 7.4（推荐 8.0+）
MySQL / MariaDB	≥ 5.7
PHP 扩展	pdo_mysql、curl、json
Web 服务器	Apache / Nginx / 宝塔面板均可
本地开发：XAMPP、PhpStudy、Laragon 等集成环境均可直接运行。

快速安装
第一步：上传文件
将整个项目目录上传至服务器 Web 根目录（或子目录），确保 PHP 对目录有写入权限（安装向导需要写入 config.php）。

# 示例：上传到 /var/www/html/novel/
第二步：访问安装向导
浏览器打开：

http://你的域名/install.php
本地环境示例：http://localhost/小说系统/install.php

第三步：填写安装信息
安装向导分两个步骤：

① 数据库连接信息

字段	说明	默认值
数据库主机	MySQL 服务器地址	localhost
数据库用户名	有建库权限的 MySQL 用户	root
数据库密码	MySQL 用户密码	空
数据库名称	系统数据库名，不存在会自动创建	ai_novel
② 设置管理员账号

字段	说明
管理员用户名	登录系统使用的账号
密码	至少 6 位，使用 bcrypt 加密存储
确认密码	两次输入须一致
第四步：完成安装
点击 「一键安装」，安装成功后自动跳转到登录页面。

安装完成后，install.php 将自动进入"已安装"保护状态，无法重复执行。

目录结构
小说系统/
├── index.php            # 书库首页（需登录）
├── create.php           # 新建小说（需登录）
├── novel.php            # 小说管理中心（需登录）
├── chapter.php          # 章节查看/编辑（需登录）
├── settings.php         # AI 模型配置（需登录）
├── login.php            # 登录页面
├── logout.php           # 退出登录
├── install.php          # 一键安装向导
├── config.php           # 系统配置（由安装向导自动生成）
│
├── api/
│   ├── actions.php      # 通用 AJAX 接口（需登录）
│   ├── write_chapter.php    # 章节写作 SSE 流（需登录）
│   ├── generate_outline.php # 大纲生成 SSE 流（需登录）
│   ├── cancel_write.php     # 取消写作（需登录）
│   └── diagnose.php         # 系统诊断
│
├── includes/
│   ├── auth.php         # 登录鉴权核心
│   ├── db.php           # PDO 数据库封装
│   ├── ai.php           # AI 客户端（OpenAI 兼容协议）
│   ├── functions.php    # 公共函数与 Prompt 构建
│   └── layout.php       # 页面头部/尾部模板
│
└── assets/
    ├── css/style.css    # 全局样式（暗色/亮色双主题）
    └── js/app.js        # 前端交互逻辑
使用指南
登录系统
访问任意页面均会自动跳转至登录页，输入安装时设置的管理员账号密码即可进入。

登录状态通过服务端 Session 维持
顶栏右上角显示当前用户名，点击「退出」可安全注销
配置 AI 模型
进入 「模型设置」 页面，添加至少一个 AI 模型后才能开始写作。

添加步骤：

在右侧「快速选择预设」中点击对应平台（如「方舟 Coding Plan」）自动填入 API 地址
填入您的 API 密钥
根据需要调整 Max Tokens 和 Temperature
勾选「设为默认模型」并点击「添加模型」
推荐：火山方舟 Coding Plan

支持 DeepSeek-V3.2 、豆包、GLM、KIMI\MiniMax 等高性能模型，OpenAI 兼容接口，按量计费，非常适合小说批量生成场景。

注册地址：https://www.volcengine.com/activity/codingplan?utm_source=7&utm_medium=daren_cpa&utm_term=daren_cpa_gaodingai_doumeng&utm_campaign=0&utm_content=codingplan_doumeng

API 地址参考：

平台	API 地址
火山方舟 Coding Plan	https://ark.cn-beijing.volces.com/api/coding/v3
DeepSeek	https://api.deepseek.com/v1
OpenAI	https://api.openai.com/v1
Moonshot (Kimi)	https://api.moonshot.cn/v1
智谱 GLM	https://open.bigmodel.cn/api/paas/v4
通义千问	https://dashscope.aliyuncs.com/compatible-mode/v1
Ollama 本地	http://localhost:11434/v1
新建小说
点击顶部「新建小说」，填写以下信息：

字段	说明	示例
书名	小说标题	《重生之我是大明星》
类型	小说题材	都市、玄幻、科幻…
写作风格	文笔风格描述	轻松幽默、热血爽文…
主角姓名	主角名字	林枫
主角背景	主角性格、背景故事	重生前是落魄歌手…
世界设定	故事发生的世界背景	现代都市，娱乐圈…
情节设定	主线剧情方向	凭借前世记忆逆袭…
其他设定	补充说明	金手指、系统辅助…
目标章数	计划写多少章	100
每章字数	每章目标字数	2000
使用模型	选择 AI 模型	已配置的默认模型
填写完成后点击「创建小说」，进入小说管理页面。

生成章节大纲
在小说管理页面（novel.php）：

点击 「生成章节大纲」 按钮
填写要生成大纲的章节范围（如第 1 章 ~ 第 20 章）
AI 将实时流式输出每章的：
章节标题
内容摘要
关键情节点（3~5 个）
结尾悬念钩子
生成完成后，章节列表中对应章节状态变为「已大纲」
建议每次批量生成 20 章，保持剧情连贯性。

AI 自动写作
大纲生成完毕后，即可开始写作：

写单章： 在章节列表中点击「写这章」，AI 自动根据大纲写出完整正文。

自动连写： 点击 「自动写作」，系统将按顺序自动写完所有「已大纲」章节，实时显示流式输出。

取消写作： 点击「取消写作」可随时中断，已写完的章节内容不会丢失。

重置章节： 如对某章内容不满意，可在章节详情页点击「重置」清空内容，重新生成。

章节管理
点击章节列表中任意章节进入 chapter.php：

阅读模式：查看完整正文、大纲、关键情节点
编辑模式：手动修改标题和正文内容，保存后自动更新字数统计
上下章导航：顶部提供前后章节快速跳转
支持的 AI 模型
系统兼容所有支持 OpenAI Chat Completions 协议的 API，内置以下预设：

预设名称	说明
方舟 Coding Plan	火山方舟，DeepSeek-V3.2，推荐首选
OpenAI GPT-4	OpenAI 官方 GPT-4
OpenAI GPT-3.5	OpenAI 官方 GPT-3.5-turbo
DeepSeek Chat	DeepSeek 官方接口
DeepSeek R1	DeepSeek 推理模型
Moonshot Kimi	月之暗面 Kimi
智谱 GLM-4	智谱 AI
通义千问 Turbo	阿里云通义
通义千问 Plus	阿里云通义 Plus
Claude Sonnet	Anthropic Claude
Ollama 本地	本机部署的开源模型
自定义	填入任意兼容接口
常见问题
Q：安装时提示"数据库连接失败"？

检查数据库主机、用户名、密码是否正确。本地开发通常使用 root + 空密码或 127.0.0.1 替代 localhost。

Q：写作时一直转圈，没有输出？

进入「模型设置」页面，使用「连接测试」功能检查 API Key 是否有效，以及 API 地址是否可以正常访问。

Q：如何更换管理员密码？

重新访问 install.php（需先清空 config.php 中 ADMIN_PASS 的值），或直接使用 PHP 命令行生成新散列值后手动替换：

echo password_hash('新密码', PASSWORD_BCRYPT);
Q：生成的大纲 / 章节质量不理想？

在「新建小说」时尽量详细填写「世界设定」「情节设定」「主角背景」，给 AI 提供充足上下文。同时可以尝试调高 Temperature（0.9~1.2）增加创意度。

Q：如何迁移数据到新服务器？

导出原服务器数据库（mysqldump ai_novel > backup.sql）
将项目文件复制到新服务器
在新服务器导入数据库，然后修改 config.php 中的数据库连接信息
技术栈
后端：PHP 8.x，PDO/MySQL，原生 SSE 流式输出
前端：Bootstrap 5.3，Bootstrap Icons，原生 JS（无框架依赖）
AI 接入：OpenAI Chat Completions 协议，支持流式 / 非流式双模式
安全：bcrypt 密码哈希，PDO 预处理防注入，Session 登录鉴权，APP_LOADED 常量防直接访问
