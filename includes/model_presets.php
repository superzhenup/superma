<?php
defined('APP_LOADED') or die('Direct access denied.');

const INSTALL_SKYHOST_API_URL = 'https://api.skyhost.cn/v1';
const INSTALL_SKYHOST_REGISTER_URL = 'https://api.skyhost.cn/';

/**
 * Presets displayed on the authenticated model settings page.
 *
 * @return array<string,array{name:string,api_url:string,model_name:string}>
 */
function getSettingsModelPresets(): array
{
    return [
        'skyhost-deepseek-v4-flash' => ['name' => 'Skyhost DeepSeek V4 Flash', 'api_url' => INSTALL_SKYHOST_API_URL, 'model_name' => 'deepseek-v4-flash'],
        'skyhost-gpt-5.5'           => ['name' => 'Skyhost GPT-5.5',           'api_url' => INSTALL_SKYHOST_API_URL, 'model_name' => 'gpt-5.5'],
        'skyhost-kimi-2.6'          => ['name' => 'Skyhost Kimi 2.6',          'api_url' => INSTALL_SKYHOST_API_URL, 'model_name' => 'kimi-2.6'],
        'skyhost-deepseek-v4-pro'   => ['name' => 'Skyhost DeepSeek V4 Pro',   'api_url' => INSTALL_SKYHOST_API_URL, 'model_name' => 'deepseek-v4-pro'],
        'skyhost-glm-5.1'           => ['name' => 'Skyhost GLM-5.1',           'api_url' => INSTALL_SKYHOST_API_URL, 'model_name' => 'glm-5.1'],
        'openai-gpt4o'              => ['name' => '方舟Coding Plan',            'api_url' => 'https://ark.cn-beijing.volces.com/api/coding/v3', 'model_name' => 'DeepSeek-V3.2'],
        'openai-gpt35'              => ['name' => '硅基流动',                   'api_url' => 'https://api.siliconflow.cn/v1', 'model_name' => 'Qwen/Qwen3.6-35B-A3B'],
        'deepseek-chat'             => ['name' => 'DeepSeek Chat',             'api_url' => 'https://api.deepseek.com/v1', 'model_name' => 'deepseek-chat'],
        'deepseek-r1'               => ['name' => 'DeepSeek R1',               'api_url' => 'https://api.deepseek.com/v1', 'model_name' => 'deepseek-reasoner'],
        'moonshot-v1'               => ['name' => 'Moonshot Kimi',             'api_url' => 'https://api.moonshot.cn/v1', 'model_name' => 'moonshot-v1-8k'],
        'zhipu-glm4'                => ['name' => '智谱 GLM-4',                'api_url' => 'https://open.bigmodel.cn/api/paas/v4', 'model_name' => 'glm-4'],
        'qwen-turbo'                => ['name' => '通义千问 Turbo',             'api_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1', 'model_name' => 'qwen-turbo'],
        'qwen-plus'                 => ['name' => '通义千问 Plus',              'api_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1', 'model_name' => 'qwen-plus'],
        'claude-sonnet'             => ['name' => 'Claude Sonnet',             'api_url' => 'https://api.anthropic.com/v1', 'model_name' => 'claude-sonnet-4-6'],
        'ollama-local'              => ['name' => 'Ollama (本地)',              'api_url' => 'http://localhost:11434/v1', 'model_name' => 'llama3'],
        'custom'                    => ['name' => '自定义模型',                 'api_url' => '', 'model_name' => ''],
    ];
}

/**
 * Fixed whitelist used during the post-install Skyhost onboarding step.
 *
 * @return array<string,array{name:string,model_name:string,max_tokens:int,temperature:float,capabilities:string[],default_selected:bool}>
 */
function getInstallSkyhostModelPresets(): array
{
    return [
        'deepseek-v4-flash' => [
            'name' => 'DeepSeek V4 Flash',
            'model_name' => 'deepseek-v4-flash',
            'max_tokens' => 8192,
            'temperature' => 0.8,
            'capabilities' => ['creative', 'structured', 'synopsis'],
            'default_selected' => true,
        ],
        'gpt-5.5' => [
            'name' => 'GPT-5.5',
            'model_name' => 'gpt-5.5',
            'max_tokens' => 8192,
            'temperature' => 0.8,
            'capabilities' => ['creative', 'structured', 'synopsis'],
            'default_selected' => true,
        ],
        'kimi-2.6' => [
            'name' => 'Kimi 2.6',
            'model_name' => 'kimi-2.6',
            'max_tokens' => 8192,
            'temperature' => 0.8,
            'capabilities' => ['creative', 'synopsis'],
            'default_selected' => true,
        ],
        'deepseek-v4-pro' => [
            'name' => 'DeepSeek V4 Pro',
            'model_name' => 'deepseek-v4-pro',
            'max_tokens' => 8192,
            'temperature' => 0.8,
            'capabilities' => ['creative', 'structured', 'synopsis'],
            'default_selected' => true,
        ],
        'glm-5.1' => [
            'name' => 'GLM-5.1',
            'model_name' => 'glm-5.1',
            'max_tokens' => 8192,
            'temperature' => 0.8,
            'capabilities' => ['creative', 'structured'],
            'default_selected' => true,
        ],
    ];
}
