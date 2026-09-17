
<div align="center">

# 🎮 Xboard RhythmGacha
### 虚空共鸣 · 律动跃迁特别企划插件

针对 Xboard 开发的次世代二次元音游出勤、战备抽卡与物资核销全生态插件  
告别枯燥的流量售卖，将网络服务化作高沉浸度、高黏性的二次元游戏俱乐部！

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=flat-square&logo=php)](https://www.php.net/)
[![XBoard Compatibility](https://img.shields.io/badge/Xboard-%3E%3D1.7.0-blue?style=flat-square)](https://github.com/cedar2025/Xboard)
[![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)](LICENSE)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg?style=flat-square)](https://github.com/)

[功能特性](#-功能特性) • [架构全景](#-架构全景) • [快速部署](#-快速部署) • [平台凭证配置](#-第三方凭据配置) • [前端集成](#-前端视窗化集成) • [二次开发](#-二次开发指南)

</div>

---

## 🌟 功能特性

### 1. 跨端音游战绩云端多维核验
* **osu! (官方 API v2 深度打通)**：
  * 支持 OAuth2 官方授权跳转或 UID 智能解析，支持 osu! Lazer 与 Stable 双轨计分标准。
  * 自动抓取并展示原版歌曲壁纸、ACC 准确率、PP 表现值、连击数与判定四宫格，直达官方谱面。
* **舞萌 DX (水鱼查分器官方 API 适配)**：
  * 兼容用户名 / QQ / Import-Token，绑定即时验明正身并回显 DX Rating 战力名片。
* **hololive Dreams (多模态 AI 物理层全内存防伪)**：
  * 采用 16 进制 Chunk 解析引擎直接提取 PNG 像素宽高与硬件时间戳。
  * **60 秒物理时效锁** + **设备分辨率乘积哈希绑定** + **判定偏移数学物理守恒**（$\text{FAST}+\text{SLOW} \equiv \text{GREAT}+\text{GOOD}+\text{BAD}$）。
  * 独创**自学习谱面知识库**与**打击指标哈希去重**，彻底杜绝翻拍、旧图套娃作弊。

### 2. 手游级抽卡与数值经济闭环
* **滑动窗口体力系统 (Lazy Evaluation)**：
  * 读时惰性计算，自然恢复速度自定义（默认 45 分钟 / 点），0 定时任务数据库 I/O 负担。
  * 支持体力溢出与管理员无限特权。
* **原神级祈愿跃迁系统 (Gacha Engine)**：
  * **5★ 硬保底 (90 抽)**：支持 UP 限定池与常驻池自由切换，具备 50% 歪卡机制与二次保底 100% 必出 UP 设置。
  * **4★ 独立保底 (逢 10 必出)**：双星级保底计数器相互独立，互不干扰。
* **战略物资背包与原子核销**：
  * 抽卡获得盲盒道具入库，用户手动二次确认核销。
  * 严格按 MB 级原子写入 Xboard 底层 `transfer_enable` 字节流，杜绝并发刷量与单位换算误差。

### 3. 独立 Telegram 伴生机器人
* **独立运作**：与 Xboard 官方机器人完全解耦，独立 Token、独立 Webhook 路由，互不踩踏。
* **单卡片无闪烁状态机**：所有子菜单、背包分页、流水查询全在同一张卡片内就地重绘。
* **全内存媒体流发送**：调阅详细战绩时，封面图全程通过 PHP 内存流直发 Telegram，用完即焚，服务器硬盘 0 读写、0 文件残留。
* **隐私物理抹除**：收到用户敏感密码或长链接时，0.1 秒自动调用 API 物理抹除消息。

---

## 🏛️ 架构全景

```text
┌─────────────────────────────────────────────────────────────┐
│                       用户交互接入层                         │
├──────────────────────────────┬──────────────────────────────┤
│  网页端: 客户端级活动视窗     │  移动端: 独立 Telegram 机器人 │
│  (内置 /app 页面 / 动态 WebP) │  (单卡片状态机 / 内存流图片)   │
└──────────────┬───────────────┴──────────────┬───────────────┘
               │                              │
               ▼                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    RhythmGacha 核心服务层                    │
├─────────────────────────────────────────────────────────────┤
│  • StaminaService: 45min 滑动窗口体力引擎 (Lazy Evaluation)   │
│  • GachaService: 90抽大小保底 / 10抽4★保底 / 多UP独立卡池   │
│  • BackpackService: 悲观锁事务核销 / MB-Byte 物理换算引擎     │
│  • GameDriverFactory: osu! / 水鱼 / hololive 统一评级归一化   │
│  • HololiveVisionService: 16进制底层取证 / 多模态紧凑流审计   │
└──────────────────────────────┬──────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────┐
│                       底层持久化与对接                       │
├──────────────────────────────┬──────────────────────────────┤
│  插件自建表体系              │  Xboard 核心系统             │
│  • ry_users / ry_items       │  • v2_user (原子累加流量)    │
│  • ry_backpack / pools       │  • payment.notify.verified   │
│  • ry_osu / mai / holo_scores│    (订单钩子自动赠送月票)    │
└──────────────────────────────┴──────────────────────────────┘
```

---

## 🚀 快速部署

### 1. 环境依赖
* PHP >= 8.2 (需启用 `bcmath`, `curl`, `json`, `mbstring`, `pdo_mysql`)
* Xboard >= 1.7.0 (兼容 Laravel Octane / Swoole 架构)
* MySQL >= 5.7 或 MariaDB >= 10.3

### 2. 安装插件
1. 克隆或下载本仓库代码，确保根目录名称为 `RhythmGacha`；
2. 将 `RhythmGacha` 文件夹打包压缩为 `RhythmGacha.zip`（确保压缩包第一层为 `RhythmGacha/` 目录）；
3. 登录 Xboard 管理后台 ➔ **插件管理** ➔ 点击 **上传插件**，选择 `.zip` 包完成上传并点击 **启用**。

### 3. 初始化数据表
登录宝塔终端或服务器 SSH，进入 Xboard 根目录执行迁移建表：

```bash
# Docker 部署执行:
docker compose exec -it xboard php artisan rhythm-gacha:install

# 独立环境部署执行:
php artisan rhythm-gacha:install
```

若使用外部数据库管理工具（如 phpMyAdmin / Navicat），亦可直接执行 [database.sql](database/schema.sql) 文件中的建表语句。

---

## ⚙️ 第三方凭据配置

进入 Xboard 后台 ➔ **插件管理** ➔ 找到 **Rhythm Gacha** 点击 **配置**：

| 配置键名 | 类型 | 默认值 | 描述与获取说明 |
| :--- | :---: | :---: | :--- |
| `stamina_base_max` | number | `10` | 普通玩家基础体力上限 |
| `stamina_vip_max` | number | `20` | 月票 (HoloPassport) 玩家体力上限 |
| `stamina_recover_mins`| number | `45` | 体力恢复周期（分钟/点） |
| `sync_interval_seconds`| number | `300`| 后台全自动巡检频率（秒），默认 5 分钟 |
| `osu_api_client_id` | string | 空 | [osu! 开发者中心](https://osu.ppy.sh/home/account/edit#oauth) 申请的 Application Client ID |
| `osu_api_client_secret`| string | 空 | osu! Application 的 Client Secret |
| `ai_vision_base_url` | string | `https://api.openai.com/v1` | 兼容 OpenAI 格式的多模态视觉 API 端点 |
| `ai_vision_api_key` | string | 空 | 多模态 AI 密钥 (支持 OneAPI / DeepSeek 等) |
| `ai_vision_model` | string | `gpt-4o-mini` | 具备视觉解析能力的模型名称 |
| `tg_bot_enable` | boolean| `false` | 是否开启专属独立 Telegram 机器人 |
| `tg_bot_token` | string | 空 | 在 [@BotFather](https://t.me/BotFather) 申请的**独立机器人专属 Token** |
| `tg_webhook_base_url`| string | 空 | 网站外网公网根域名 (如 `https://next.example.com`) |

---

## 🤖 独立 Telegram 机器人激活

> ⚠️ **注意**：独立机器人必须在 `@BotFather` 单独注册一个全新的 Bot，**严禁使用 Xboard 官方面板已绑定的同一个 Token**，否则会导致 Webhook 冲突！

1. 在插件配置中将 `tg_bot_enable` 设为 `开启`，填入独立的 `tg_bot_token` 与公网 `tg_webhook_base_url` 并保存；
2. 直接在浏览器访问以下激活端点（系统将自动向 Telegram 注册 Webhook 并挂载指令菜单）：
   ```text
   https://你的域名/api/v1/rhythm-gacha/telegram/set-webhook
   ```
3. 看到返回 `{"status":"success", ...}` 即代表激活完毕！在 Telegram 即可使用 `/start`、`/panel`、`/login`。

---

## 🎨 前端视窗化集成 (示例页脚 HTML)

本方案采用 Material Design 3 视窗引擎，直接嵌入在 Xboard 用户端，支持平滑缩放、8 维度拉伸与双按钮并排停靠。

前往 Xboard 管理后台 ➔ **系统管理** ➔ **主题配置** ➔ **自定义页脚HTML**，粘贴以下代码即可全自动完成挂载与 Token 穿透直传：

```html
<!-- Material Design 3 視窗化縮放引擎與雙懸浮組件 (活動+下載 雙軌極致版) -->
<div id="reia-md3-wrapper"></div>

<script>
  (function() {
    const DOWNLOAD_URL = 'https://install.reia.fans'; // 客户端下载地址
    const GACHA_URL = (window.location.origin.includes('rka.jp') ? window.location.origin : window.location.origin) + '/api/v1/rhythm-gacha/app';
    
    let isInitialized = false;

    function checkAndInit() {
      if (isInitialized) return;
      if (document.body && (document.getElementById('app') || document.querySelector('main'))) {
        clearInterval(watcher);
        clearTimeout(safetyTimeout);
        initComponent();
      }
    }

    document.addEventListener('DOMContentLoaded', checkAndInit);
    const watcher = setInterval(checkAndInit, 100);
    const safetyTimeout = setTimeout(() => { clearInterval(watcher); initComponent(); }, 1200);

    function initComponent() {
      isInitialized = true;
      injectStyles();
      injectHTML();
      setupWindowSystem();
    }

    function injectStyles() {
      const style = document.createElement('style');
      style.textContent = `
        .md3-fab-dock {
          position: fixed; bottom: 24px; right: 24px; z-index: 999999;
          display: flex; align-items: center; gap: 10px; flex-wrap: nowrap !important; pointer-events: none;
        }
        .md3-fab {
          position: relative; pointer-events: auto !important; display: inline-flex; align-items: center;
          gap: 8px; padding: 0 18px; height: 50px; border-radius: 16px;
          background: #1e293b; color: #fff; border: 1px solid rgba(255,255,255,0.1);
          box-shadow: 0px 4px 12px rgba(0,0,0,0.3); cursor: pointer; font-weight: 600; font-size: 13px;
          white-space: nowrap !important; transition: transform 0.2s, box-shadow 0.2s;
        }
        .md3-fab:hover { transform: translateY(-2px); box-shadow: 0px 6px 16px rgba(0,0,0,0.4); }
        .md3-fab-gacha {
          background: linear-gradient(135deg, #f43f5e 0%, #8b5cf6 100%) !important;
          color: #fff !important; border: none !important;
          box-shadow: 0px 4px 15px rgba(244, 63, 94, 0.4) !important;
        }
        .md3-dialog-overlay {
          display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6);
          backdrop-filter: blur(6px); z-index: 10001; align-items: center; justify-content: center;
        }
        .md3-dialog-overlay.active { display: flex; }
        .md3-dialog {
          position: fixed; background: #0b0f19; color: #fff; width: 920px; height: 680px;
          border-radius: 20px; border: 1px solid rgba(56,189,248,0.3);
          box-shadow: 0 20px 50px rgba(0,0,0,0.7); display: flex; flex-direction: column; overflow: hidden;
        }
        .md3-dialog__header {
          display: flex; align-items: center; justify-content: space-between;
          padding: 12px 20px; background: #111827; border-bottom: 1px solid rgba(255,255,255,0.08); cursor: move;
        }
        .md3-dialog__body { flex: 1; position: relative; background: #000; }
        .md3-dialog__body iframe { width: 100%; height: 100%; border: none; }
        @media (max-width: 640px) {
          .md3-fab-dock { bottom: 16px; right: 12px; gap: 6px; }
          .md3-fab { height: 44px; padding: 0 12px; font-size: 12px; border-radius: 12px; }
          .md3-dialog { width: 100vw !important; height: 100vh !important; border-radius: 0 !important; }
        }
      `;
      document.head.appendChild(style);
    }

    function injectHTML() {
      document.getElementById('reia-md3-wrapper').innerHTML = `
        <div class="md3-fab-dock">
          <button id="reia-btn-gacha" class="md3-fab md3-fab-gacha">
            <span>🎮</span><span>玩游戏领流量</span>
          </button>
          <button id="reia-btn-download" class="md3-fab">
            <span>📥</span><span>客户端下载</span>
          </button>
        </div>
        <div id="reia-overlay" class="md3-dialog-overlay">
          <div id="reia-window" class="md3-dialog">
            <div id="reia-header" class="md3-dialog__header">
              <span id="reia-title" style="font-weight:bold; font-size:14px;">REIA 终端</span>
              <div>
                <button onclick="document.getElementById('reia-overlay').classList.remove('active')" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:18px;">✕</button>
              </div>
            </div>
            <div class="md3-dialog__body">
              <iframe id="reia-frame" src="about:blank"></iframe>
            </div>
          </div>
        </div>
      `;
    }

    function setupWindowSystem() {
      const overlay = document.getElementById('reia-overlay');
      const frame = document.getElementById('reia-frame');
      const title = document.getElementById('reia-title');

      function getPanelToken() {
        try {
          const keys = ['XBOARD_ACCESS_TOKEN', '__AURORA_authorization', 'token', 'auth_data'];
          for (const k of keys) {
            const val = localStorage.getItem(k);
            if (!val) continue;
            try {
              const p = JSON.parse(val);
              if (p?.value) return p.value.replace(/^Bearer\s+/i, '').trim();
            } catch(e) {
              if (val.length > 20) return val.replace(/^Bearer\s+/i, '').trim();
            }
          }
        } catch(e) {}
        return '';
      }

      function openWindow(url, titleText) {
        title.innerText = titleText;
        if (frame.src !== url) frame.src = url;
        overlay.classList.add('active');
      }

      document.getElementById('reia-btn-gacha').addEventListener('click', () => {
        const token = getPanelToken();
        const url = token ? `${GACHA_URL}?token=${encodeURIComponent(token)}` : GACHA_URL;
        openWindow(url, '🎮 律动跃迁 · 玩游戏领流量特别企划');
      });

      document.getElementById('reia-btn-download').addEventListener('click', () => {
        openWindow(DOWNLOAD_URL, '📥 客户端下载与使用指南');
      });

      window.addEventListener('message', (e) => {
        if (e.data && e.data.type === 'CLOSE_REIA_DIALOG') overlay.classList.remove('active');
      });
    }
  })();
</script>
```

---

## 🛠️ 二次开发指南

### 1. 扩展新音游接入驱动 (Driver Pattern)
所有音游对接均遵循标准驱动接口。新增游戏（如 *Phigros*, *Arcaea*, *Steam全家桶*）仅需在 `Services/Game/` 目录下新建类并实现 `GameDriverInterface`：

```php
namespace Plugin\RhythmGacha\Services\Game;

interface GameDriverInterface
{
    /**
     * @param string $identifier 用户在前端绑定的账号标识符
     * @return array|null 必须返回标准结构: ['level' => 1~5, 'score_id' => '全局唯一查重ID', 'raw' => [...]]
     */
    public function fetchLatestScore(string $identifier): ?array;
}
```

随后在 `GameApiFactory::make($gameType)` 中追加对应分支即可。

### 2. 评级与宝石折算标准归一矩阵
系统统一将各家复杂的小数点与字母评分映射为 5 个标准 Level：

| 标准等级 | 宝石奖励 | 舞萌 DX 达成率 | osu! 官方评级 | PJSK 评级 |
| :---: | :---: | :--- | :--- | :--- |
| **Level 5** | **100 💎** | $\ge 99.0000\%$ (SS / SSS / SSS+) | XH / X (SS) | ALL PERFECT / FULL COMBO |
| **Level 4** | **75 💎** | $97.0\% \sim 98.99\%$ (S / S+) | S / SH | S 级通关 |
| **Level 3** | **50 💎** | $80.0\% \sim 96.99\%$ (A / AA / AAA) | A 级 | A 级通关 |
| **Level 2** | **25 💎** | $60.0\% \sim 79.99\%$ (B / BB / BBB) | B 级 | B 级通关 |
| **Level 1** | **10 💎** | $< 60.0\%$ (C / D 及其他) | C / D / Failed | C 级及以下 |

---

## 📄 开源协议与免责声明

1. 本项目基于 [MIT License](LICENSE) 协议开源，允许自由商用、二次修改与分发；
2. 本项目所调用的 osu!、舞萌 DX（水鱼查分器）等接口均为公开合法 API，不涉及对任何商业客户端的注入、修改或逆向篡改；
3. 本项目仅供技术交流与学习，服主应对所发放的虚拟积分及网络服务合法合规性自行负责。
```

---

### 💡 仓库提交文件结构参考

建议你在 GitHub 提交时保持如下纯净目录：

```text
.
├── Commands/                      # Artisan 命令库
├── Controllers/                   # RESTful API 控制器
├── Events/                        # 领域事件定义
├── Listeners/                     # 事件监听与发货总线
├── Models/                        # Eloquent ORM 数据模型
├── Services/                      # 核心业务逻辑 (体力/抽卡/防伪/驱动)
├── database/                      # 基础数据库 SQL 备份
├── resources/                     # 前端活动视图与 loading.webp 资产
├── routes/                        # 插件路由定义
├── config.json                    # 插件声明与配置项
├── Plugin.php                     # 插件启动主类
├── README.md                      # 完整项目文档 (上面的内容)
└── LICENSE                        # MIT License
```