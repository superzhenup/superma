# ✦ Super-Ma AI 智能小说创作辅助系统（平台）
## UI预览
<img width="1921" height="906" alt="局部截取_20260427_204024" src="https://github.com/user-attachments/assets/5951459d-bada-4d90-b443-1ad7127c53e1" />
<p> <p>
<img width="1895" height="904" alt="局部截取_20260427_204121" src="https://github.com/user-attachments/assets/97c06eaf-12d1-4a89-a985-39d76f98e234" />
<p> <p>
<img width="1919" height="910" alt="局部截取_20260427_204252" src="https://github.com/user-attachments/assets/31c1d69a-350b-4586-988a-0eaf2e946524" />


## 功能简介

<p> 🤖独创 | 首次将Agents融入到AI小说智能辅助系统：系统内置 WritingStrategyAgent（写作策略）、QualityMonitorAgent（质量监控）、OptimizationAgent（系统优化）三大智能 Agent，三位一体协同决策，动态调整字数容差、爽点密度、节奏策略与质量严格度，让AI持续赋能写作。<p>
<p> 📊内置 | 深度定制：基于1500+本白金级网文深度学习，量化网文数据，构建小说创作专属知识图谱。章节生成完成后，系统自动解析并提取核心创作要素——角色档案卡、伏笔埋线追踪、世界观设定集，沉淀为可复用、可检索的结构化创作资产，助力长期连载的一致性与深度。<p>
<p> 🧠创新 | 持久记忆：Pyramid MemoryEngine 记忆引擎 （L1全局设定 / L2弧段摘要 / L3近章大纲 / L4前章尾文），配合 Embedding 语义向量检索，越写越聪明，有效防止长篇创作中的情节失忆与人物漂移（灵感来源：TencentDB AI-Memory ）。<p>
<p> 🖥️支持 | 可视化写作：支持SSE（流式写作）、CLI（异步后台写作）、Automatic offline（挂机全自动连续写作）三大写作模式，动态字数控制系统精确把控章节篇幅，模型 Fallback 机制保障写作连续性。<p>

<p>---<p>

## 目录

- [功能特性](#功能特性)
- [环境要求](#环境要求)
- [快速安装](#快速安装)
- [使用指南](#使用指南)
- [记忆引擎 v6](#记忆引擎-v6)
- [Agents决策系统](#Agents决策系统)
- [支持的 AI 模型](#支持的-ai-模型)
- [常见问题](#常见问题)
- [技术栈](#技术栈)
- [更新日志](#更新日志)

---

## 功能特性

| 功能 | 说明 |
|------|------|
| 🤖 多Agent 决策系统 | WritingStrategy/QualityMonitor/Optimization 三位一体协同，动态优化写作参数
| 🔐 登录验证 | 账号密码登录，Session + CSRF 鉴权，全站接口保护 |
| ⚡ 一键安装 | 填写数据库信息和管理员账号，自动建库建表 |
| 📚 书库管理 | 多部小说并行管理，卡片式展示进度与状态 |
| 🗂 大纲生成 | 批量生成章节大纲（标题、摘要、关键情节、结尾钩子） |
| ✍️ 流式写作 | SSE 实时流式输出，写作进度可视化，支持中途取消 |
| 🖥️ 异步写作 | CLI 后端进程 + 进度文件轮询，绕过 Nginx/FPM 超时限制 |
| 🧠 记忆引擎 | 四层记忆架构（L1全局 / L2弧段 / L3近章 / L4前章尾文），防止长篇失忆 |
| 🔄 模型 Fallback | 主模型失败时自动切换备用模型，保障写作连续性 |
| 🤖 多模型支持 | 兼容所有 OpenAI 协议 API，内置 13 种预设配置 |
| 🎨 双主题 | 暗色 / 亮色主题自由切换，保护持久保护视力 |
| 🔍 语义召回 | Embedding 向量检索，双路召回长尾记忆原子 |
| ✅ 质量检测 | 章节完成后自动质检（结构/人物/描写/爽点/一致性） |
| 📊 知识库 | 角色/世界观/情节自动提取，章节完成即入库 |
| 🎯 动态字数控制 | 基于目标字数的动态容差机制 + 多级预警系统，精准控制章节篇幅 |
| ⭐ 其他功能： 一键仿写、挂机写作（Pro订阅专属）、封面生成（Pro订阅专属）
---

## 环境要求

| 组件 | 版本要求 |
|------|---------|
| PHP | ≥ 8.0（推荐 8.2+） |
| MySQL / MariaDB | ≥ 5.7 |
| PHP 扩展 | `pdo_mysql`、`curl`、`json` |
| Web 服务器 | Apache / Nginx / 宝塔面板均可 |

> **本地开发**：宝塔、XAMPP、PhpStudy、Laragon 等集成环境均可直接运行。

---

## 快速安装

### 第一步：上传文件

将整个项目目录上传至服务器 Web 根目录（或子目录），确保 PHP 对目录有**写入权限**（安装向导需要写入 `config.php`）。

### 第二步：访问安装向导

浏览器打开：

```
http://你的域名/install.php
```

### 第三步：填写安装信息

**① 数据库连接信息**

| 字段 | 说明 | 默认值 |
|------|------|--------|
| 数据库主机 | MySQL 服务器地址 | `localhost` |
| 数据库用户名 | 有建库权限的 MySQL 用户 | `root` |
| 数据库密码 | MySQL 用户密码 | 空 |
| 数据库名称 | 系统数据库名，不存在会自动创建 | `ai_novel` |

**② 设置管理员账号**

| 字段 | 说明 |
|------|------|
| 管理员用户名 | 登录系统使用的账号 |
| 密码 | 至少 6 位，使用 bcrypt 加密存储 |
| 确认密码 | 两次输入须一致 |

### 第四步：完成安装

点击 **「一键安装」**，安装成功后自动跳转到登录页面。

> 安装完成后，访问`install.php` 将自动进入"已安装"保护状态，无法重复执行。


---

## 相关资源

- **系统模块架构可视化**（推荐）：`docs/system-modules-visualization.html` - 包含 8 大核心模块详解、6 阶段写作引擎、Agent 系统、四层记忆架构
- **系统运行时可视化**：`docs/system-runtime-visualization.html` - 系统运行时逻辑流程图

---

## 使用指南

### 登录系统

访问任意页面均会自动跳转至登录页，输入安装时设置的管理员账号密码即可进入。

### 配置 AI 模型

进入 **「模型设置」** 页面，添加至少一个 AI 模型后才能开始写作。

**推荐：火山方舟 Coding Plan** — 支持 DeepSeek-V3.2、豆包、GLM、KIMI、MiniMax 等高性能模型，OpenAI 兼容接口，量大管饱。

**API 地址参考：**

| 平台 | API 地址 |
|------|---------|
| 火山方舟 Coding Plan | `https://ark.cn-beijing.volces.com/api/coding/v3` |
| DeepSeek | `https://api.deepseek.com/v1` |
| OpenAI | `https://api.openai.com/v1` |
| Moonshot (Kimi) | `https://api.moonshot.cn/v1` |
| 智谱 GLM | `https://open.bigmodel.cn/api/paas/v4` |
| 通义千问 | `https://dashscope.aliyuncs.com/compatible-mode/v1` |
| Ollama 本地 | `http://localhost:11434/v1` |


**便宜服务器（服务器部署更稳定）：**

<p>  腾讯云 99元良心云 | `https://curl.qcloud.com/CK0gNnTC` <p> 
<p>  腾讯云 一流大牌云 | `https://www.aliyun.com/minisite/goods?userCode=null` <p> 


### 配置写作参数

进入 **「写作参数设置」** 页面，可调整以下核心参数：

**基础生成参数：**
- **每章目标字数**：单章生成的目标字数（推荐 1500-2500 字）
- **动态容差比例**：根据目标字数动态计算容差（默认 10%）
- **最小/最大容差字数**：动态容差的上下限（默认 100-500 字）

**动态字数控制系统：**
- 系统会根据目标字数自动计算容差范围（如 2000 字 × 10% = 200 字容差）
- 写作过程中会触发多级预警（70%/80%/90%/95%），逐步加强约束
- 达到 80% 字数时强制进入钩子收尾段，确保自然收尾

**爽点调度参数：**
- **爽点密度目标值**：每章平均爽点数量（推荐 0.88 个/章）
- **爽点饥饿阈值**：同类型爽点冷却期过后可重新参选的比例
- **双爽点最小间隔**：连续出现"双爽点章"的最小间隔章数

**章节结构参数：**
- **四段式结构占比**：铺垫段（20%）、发展段（30%）、高潮段（35%）、钩子段（15%）

### 新建小说

点击顶部「新建小说」，填写书名、类型、写作风格、主角背景、世界设定、情节设定、目标章数、每章字数等。

### 生成章节大纲

在小说管理页面点击「生成章节大纲」，填写范围（如 1~20 章），AI 流式输出每章标题、摘要、关键情节点、结尾钩子。

### AI 自动写作

大纲生成完毕后：
- **写单章**：点击「写这章」开始单章流式写作
- **自动连写**：点击「自动写作」按顺序写完所有「已大纲」章节
- **异步写作**：系统自动检测 `exec()` 可用性，优先使用 CLI 后台进程模式，绕过 Nginx 超时限制；不可用时回退 SSE 直连模式
- **取消写作**：随时中断，章节状态自动回退
- **重置章节**：在章节详情页点击「重置」重新生成
- **挂机写作**：开启后系统自动连续写完所有章节，无需手动操作

---

## 记忆引擎 v6

为解决 AI 长篇创作易失忆的问题，本项目实现了四层记忆架构：

| 层级 | 作用范围 | 来源 | 优先级 |
|------|---------|------|--------|
| **L1 全局设定** | 全书所有章节 | `novels` 表（主角/世界观/情节/风格） | P0（绝不丢弃） |
| **L2 弧段摘要** | 每 10 章压缩一次 | `arc_summaries` 表 | P1 |
| **L3 近章大纲** | 最近 8 章 | `chapters` 表 | P1 |
| **L4 前章尾文** | 前一章最后 500–1000 字 | `chapters.content` | P0（绝不丢弃） |

**核心能力：**
- **章节完成后自动吞入**：人物状态 → `character_cards`，伏笔 → `foreshadowing_items`，关键事件 → `memory_atoms`，故事势能 → `novel_state`。
- **Prompt 构建前统一召回**：`MemoryEngine::getPromptContext()` 一次性取出 L1-L4 + 人物/伏笔/事件，带 token 预算裁剪。
- **语义召回**：接入 embedding 模型后，自动做关键词 + 向量双路召回长尾记忆原子。
- **伏笔管理**：自动跟踪 deadline，临近时在 prompt 里标记 ⚠️ 紧急提示。

详见 `docs/memory-engine-v6-update.md`。

---

## Agents决策系统

系统内置三个智能 Agent，协同优化写作过程：

### WritingStrategyAgent（写作策略 Agent）
- **触发条件**：每 10 章执行一次决策
- **控制参数**：
  - `ws_chapter_word_tolerance_ratio`：字数容差比例
  - `ws_cool_point_density_target`：爽点密度目标值
  - `ws_pacing_strategy`：节奏策略（fast/medium/slow）
- **决策逻辑**：分析最近章节的字数偏差、爽点分布、节奏表现，动态调整参数

### QualityMonitorAgent（质量监控 Agent）
- **触发条件**：每 5 章执行一次决策
- **监控指标**：
  - 字数控制准确率、爽点密度、情绪词汇密度
  - 对话比例、描写密度、人物一致性
  - 情节重复度、钩子有效性
- **控制参数**：
  - `ws_quality_strictness`：质量严格度
  - `character_check_frequency`：人物检查频率
  - `cool_point_intensity`：爽点强度
  - `enable_sensory_details`：感官细节开关

### OptimizationAgent（系统优化 Agent）
- **触发条件**：每 20 章执行一次决策
- **优化维度**：
  - 并行写入开关、Prompt 缓存 TTL
  - 上下文压缩、智能截断
  - Prompt 增强等级、质量检查深度
- **控制参数**：
  - `enable_parallel_write`：并行写入
  - `prompt_cache_ttl`：缓存有效期
  - `context_compression_enabled`：上下文压缩
  - `smart_truncation_enabled`：智能截断

### Agent 指令注入机制
- Agent 决策结果写入 `agent_directives` 表
- 写作时自动注入 Prompt 的"【🤖 Agent 指令（本章写作必须遵循）】"部分
- 自然语言指令，包含具体数据和上下文

---

## 支持的 AI 模型

系统兼容所有支持 **OpenAI Chat Completions 协议**的 API，内置以下预设：

| 预设名称 | 说明 |
|---------|------|
| 方舟 Coding Plan | 火山方舟，DeepSeek-V3.2，推荐首选 |
| OpenAI GPT-4 | OpenAI 官方 GPT-4 |
| OpenAI GPT-3.5 | OpenAI 官方 GPT-3.5-turbo |
| DeepSeek Chat | DeepSeek 官方接口 |
| DeepSeek R1 | DeepSeek 推理模型 |
| Moonshot Kimi | 月之暗面 Kimi |
| 智谱 GLM-4 | 智谱 AI |
| 通义千问 Turbo | 阿里云通义 |
| 通义千问 Plus | 阿里云通义 Plus |
| Claude Sonnet | Anthropic Claude |
| Ollama 本地 | 本机部署的开源模型 |
| 自定义 | 填入任意兼容接口 |

---

## 常见问题

**Q：安装时提示"数据库连接失败"？**
> 检查数据库主机、用户名、密码是否正确。本地开发通常使用 `root` + 空密码或 `127.0.0.1` 替代 `localhost`。

**Q：写作时一直转圈，没有输出？**
> 进入「模型设置」页面，使用「连接测试」功能检查 API Key 是否有效，以及 API 地址是否可以正常访问。

**Q：如何更换管理员密码？**
> 重新访问 `install.php`（需先清空 `config.php` 中 `ADMIN_PASS` 的值），或直接使用 PHP 命令行生成新散列值后手动替换：
> ```php
> echo password_hash('新密码', PASSWORD_BCRYPT);
> ```

**Q：生成的大纲 / 章节质量不理想？**
> 在「新建小说」时尽量详细填写「世界设定」「情节设定」「主角背景」。同时可以尝试调高 Temperature（0.9~1.2）增加创意度。

**Q：如何启用语义召回（Embedding）？**
> 在「模型设置」里添加一个 embedding 模型（勾选 `can_embed`），然后在「系统设置」里将其设为全局 embedding 模型。MemoryEngine 会在写作前自动补齐记忆原子的向量索引。

**Q：大纲生成或写作时频繁"连接中断"？**
> 这是 Nginx 的 `fastcgi_read_timeout` 默认60秒导致的。请在宝塔面板或 Nginx 配置中增加超时时间：
> ```nginx
> location ~ \.php$ {
>     fastcgi_read_timeout 300s;   # 5分钟，适合长文本生成
>     fastcgi_send_timeout 300s;
>     proxy_read_timeout 300s;
> }
> ```
> 修改后重启 Nginx 生效。如果使用 Apache 则无需此配置。

**Q：服务器禁用了 exec() 函数？**
> 系统会自动检测并回退到 SSE 直连模式，不影响正常使用。但 SSE 模式受 Nginx 超时限制，建议同时调整上面的超时配置。

---

## 技术栈

- **后端**：PHP 8.x，PDO/MySQL，原生 SSE 流式输出
- **前端**：Bootstrap 5.3，Bootstrap Icons，原生 JS（无框架依赖）
- **AI 接入**：OpenAI Chat Completions 协议，支持流式 / 非流式双模式
- **安全**：bcrypt 密码哈希，PDO 预处理防注入，Session 登录鉴权，`APP_LOADED` 常量防直接访问
- **智能控制**：
  - 动态字数控制：线性插值算法 + 多级预警机制
  - Agent 决策系统：基于规则的参数优化引擎
  - 记忆架构：四层分层记忆 + 语义召回
- **数据存储**：
  - 系统配置：`system_settings` 表（键值对存储）
  - Agent 指令：`agent_directives` 表（自然语言指令）
  - 记忆原子：`memory_atoms` 表（向量索引支持）
  - 人物卡片：`character_cards` 表（状态跟踪）

---

## 更新日志

### v1.3（2026-04-27）

- **修复所有已知BUG，提升系统稳定性**
- **新增动态字数控制系统**：
  - 动态容差机制：根据目标字数自动计算容差范围（比例可配置）
  - 多级预警系统：70%/80%/90%/95% 四级预警，逐步加强约束
  - 智能收尾：80%字数时强制进入钩子段，确保自然收尾
  - 配置界面：在写作参数设置中可调整容差比例、最小/最大容差值
- **多Agent决策机制， 三位一体智能写作增强**：
  - WritingStrategyAgent：每10章自动调整字数容差、爽点密度、节奏策略
  - QualityMonitorAgent：每5章监控质量指标，动态调整严格度
  - OptimizationAgent：每20章优化系统参数（并行写入、缓存、压缩等）
  - Agent 指令注入机制：自然语言指令自动注入写作 Prompt
  - **书籍封面管理**：
  - 支持接入Gpt-image-2 一键生成封面（Super Ma Pro订阅会员独享功能）
  - 支持手动设置封面
  



### v1.2（2026-04-25）

- **新增异步写作机制**：CLI 后台进程 + 进度文件轮询，彻底绕过 Nginx/FPM 超时限制
- **新增挂机写作**：开启后自动连续写完所有章节，无需手动干预（Super Ma Pro订阅会员独享功能）
- **新增章节工坊**：批量优化大纲、补写、润色等操作
- **新增写作参数全局设置**：集中管理超时时长、字数容差、重试策略等
- **新增动态字数控制系统**：
  - 动态容差机制：根据目标字数自动计算容差范围（比例可配置）
  - 多级预警系统：70%/80%/90%/95% 四级预警，逐步加强约束
  - 智能收尾：80%字数时强制进入钩子段，确保自然收尾
  - 配置界面：在写作参数设置中可调整容差比例、最小/最大容差值
- **Agent 决策系统**：
  - WritingStrategyAgent：每10章自动调整字数容差、爽点密度、节奏策略
  - QualityMonitorAgent：每5章监控质量指标，动态调整严格度
  - OptimizationAgent：每20章优化系统参数（并行写入、缓存、压缩等）
- **代码质量修复**：
  - 修复 `write_chapter.php` 异步模式控制流 bug（SSE headers 在入参解析前发送）
  - 修复 `write_engine.php` 状态变更缺少事务包裹
  - 修复 `cancel_write.php` 未识别 action 时 `$message` 未定义
  - 修复 `write_start.php` 启动确认超时过短（1s → 15s）
  - 修复 `novel.php` SQL 注入（参数化查询）
- **架构优化**：章节正文优先落盘、SSE 流提前关闭、后处理异步执行
- **记忆引擎 DB Schema v6**：补全 `cool_point` 记忆原子类型、`novel_plots` 状态枚举扩展

### v1.1

- 初始发布：修复已知BUG，完善记忆引擎V6


### v1.0

- 初始发布：书库管理、大纲生成、流式写作、记忆引擎 v6、多模型支持

---

## Contact me
  - Name: Kianxu
  - WeChat: itzo-cn
  - E-maill: v@goloo.cc

## License

GPLv3
