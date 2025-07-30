<?php

// 硬编码用户名和密码 (仅为演示目的，实际应用中绝不能如此处理)
define('VALID_USERNAME', 'root');
define('VALID_PASSWORD', 'password');

// --- PHP 后端逻辑 (处理 AJAX 请求) ---
if (isset($_POST['command'])) {
    header('Content-Type: application/json');

    $command = trim($_POST['command']);
    $output = '';

    // 从前端JS获取当前状态（注意：这些变量在每次请求时都是新的）
    $isAuthenticated = filter_var($_POST['isAuthenticated'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $authStep = $_POST['authStep'] ?? 'username';
    $tempUsername = $_POST['tempUsername'] ?? '';
    $currentCwd = $_POST['cwd'] ?? getcwd(); // JS未提供则默认为脚本当前目录

    // 初始化下一轮的状态变量（默认继承当前状态）
    $nextAuthenticated = $isAuthenticated;
    $nextAuthStep = $authStep;
    $nextTempUsername = $tempUsername;
    $nextCwd = $currentCwd; // 除非cd命令改变，否则CWD保持不变

    // --- 认证或命令执行逻辑 ---
    if (!$nextAuthenticated) {
        // 认证逻辑
        if ($authStep === 'username') {
            $nextTempUsername = $command;
            if ($command === VALID_USERNAME) {
                $nextAuthStep = 'password';
            } else {
                $nextAuthStep = 'username';
                $nextTempUsername = '';
                $output = "Login incorrect\n";
            }
        } elseif ($authStep === 'password') {
            if ($tempUsername === VALID_USERNAME && $command === VALID_PASSWORD) {
                $nextAuthenticated = true;
                $nextAuthStep = 'authenticated';
                $nextTempUsername = '';
                $output = "Welcome to PHP Web Terminal!\n";
            } else {
                $nextAuthStep = 'username';
                $nextTempUsername = '';
                $output = "Login incorrect\n";
            }
        }
    } else {
        // 命令执行逻辑 (认证通过后)
        if (empty($command)) {
            // 空命令，无需输出
        }
        // 特殊处理 'cd' 命令，更新JS维护的当前工作目录
        else if (substr($command, 0, 3) === 'cd ') {
            $target_dir = trim(substr($command, 3));
            $home_dir = getenv('HOME') ?: '/';
            if (empty($home_dir) || !is_dir($home_dir)) {
                $home_dir = $_SERVER['DOCUMENT_ROOT'];
            }
            if (empty($target_dir) || $target_dir === '~' || $target_dir === '$HOME') {
                $target_dir = $home_dir;
            }

            // 基于JS传入的当前CWD来解析目标路径
            $new_cwd_resolved = realpath($currentCwd . '/' . $target_dir);
            if ($new_cwd_resolved === false) {
                $new_cwd_resolved = realpath($target_dir); // 尝试绝对路径
            }

            if ($new_cwd_resolved && is_dir($new_cwd_resolved) && is_readable($new_cwd_resolved)) {
                $nextCwd = $new_cwd_resolved; // 更新下一轮的CWD
            } else {
                $output = "cd: $target_dir: No such file or directory\n";
            }
        } else {
            // 对于其他命令，先切换到JS维护的CWD，再执行命令
            $full_command = 'cd ' . escapeshellarg($currentCwd) . ' && ' . $command . ' 2>&1';
            $output = shell_exec($full_command);

            // 如果命令是 'clear' 或 'cls'，则清空前端显示
            if (strtolower($command) === 'clear' || strtolower($command) === 'cls') {
                $output = '[[CLEAR_SCREEN]]';
            }
        }
    }

    // 在所有逻辑处理完毕后，生成最终的提示符
    $prompt = get_dynamic_prompt($nextCwd, $nextAuthenticated, $nextAuthStep);

    // 将所有更新后的状态返回给前端JS
    echo json_encode([
        'output' => $output,
        'prompt' => $prompt,
        'isAuthenticated' => $nextAuthenticated,
        'authStep' => $nextAuthStep,
        'tempUsername' => $nextTempUsername,
        'cwd' => $nextCwd
    ]);
    exit;
}

// --- 辅助函数：生成动态提示符 ---
// 接收当前状态作为参数，不再依赖会话或全局变量
function get_dynamic_prompt($current_cwd_param, $is_authenticated_param, $auth_step_param) {
    if (!$is_authenticated_param) {
        if ($auth_step_param === 'username') return 'Login as: ';
        if ($auth_step_param === 'password') return 'Password: ';
        return 'Login as: '; // 默认
    }

    $user = trim(shell_exec('whoami 2>/dev/null')) ?: 'user';
    $host = trim(shell_exec('hostname 2>/dev/null')) ?: 'localhost';
    $home_dir = getenv('HOME') ?: '/';

    $display_cwd = $current_cwd_param;
    if (strpos($current_cwd_param, $home_dir) === 0) {
        $display_cwd = '~' . substr($current_cwd_param, strlen($home_dir));
    }
    if (empty($display_cwd) || $display_cwd === '/') {
        $display_cwd = '~';
    }

    $prompt_char = ($user === 'root') ? '#' : '$';
    return "$user@$host:$display_cwd$prompt_char ";
}

// 页面加载时的初始状态（由PHP生成，传递给JS）
$initial_state_for_js = [
    'isAuthenticated' => false,
    'authStep' => 'username',
    'tempUsername' => '',
    'cwd' => getcwd() // PHP脚本的初始工作目录
];
$initial_state_for_js['prompt'] = get_dynamic_prompt(
    $initial_state_for_js['cwd'],
    $initial_state_for_js['isAuthenticated'],
    $initial_state_for_js['authStep']
);

// --- HTML/CSS/JavaScript 前端 ---
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>网页终端</title>
    <style>
        body {
            background-color: #000;
            color: #fff;
            font-family: monospace;
            font-size: 14px;
            overflow-y: auto;
            height: 100vh;
            box-sizing: border-box;
        }
        #output {
            white-space: pre-wrap;
            word-wrap: break-word;
            line-height: 1.4;
            padding-bottom: 20px;
        }
        .prompt {
            color: #00ff00;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .input {
            flex-grow: 1;
            background-color: transparent;
            font-family: monospace;
            border: none;
            outline: none;
            color: #fff;
            caret-color: #fff;
            min-width: 1px;
        }
        /* 手机端优化 */
        @media (max-width: 768px) {
            body { font-size: 12px; }
            .input, .prompt { font-size: 12px; }
        }
    </style>
</head>
<body>
    <div id="output"></div>

    <script>
        const terminalOutput = document.getElementById('output');
        let currentCommandInput = null;
        let commandHistory = [];
        let historyIndex = -1;

        // PHP 传递过来的初始状态
        const initialState = <?= json_encode($initial_state_for_js); ?>;

        // JavaScript 变量来维护状态
        let state = {
            isAuthenticated: initialState.isAuthenticated,
            authStep: initialState.authStep,
            tempUsername: initialState.tempUsername,
            cwd: initialState.cwd
        };

        // 滚动到终端底部
        const scrollToBottom = () => {
            terminalOutput.scrollTop = terminalOutput.scrollHeight;
        };

        // 创建并显示新的提示符和输入框
        const renderPromptAndInput = (promptText) => {
            const promptLine = document.createElement('div');
            promptLine.className = 'prompt-line';

            const promptSpan = document.createElement('span');
            promptSpan.className = 'prompt';
            promptSpan.textContent = promptText;

            const commandInput = document.createElement('input');
            commandInput.type = (state.authStep === 'password') ? 'password' : 'text';
            commandInput.className = 'input';
            commandInput.autofocus = true;
            commandInput.autocapitalize = 'off';
            commandInput.autocomplete = 'off';
            commandInput.spellcheck = 'false';

            promptLine.appendChild(promptSpan);
            promptLine.appendChild(commandInput);
            terminalOutput.appendChild(promptLine);

            currentCommandInput = commandInput;
            commandInput.addEventListener('keydown', handleCommandInputKeydown);

            scrollToBottom();
            commandInput.focus();
        };

        // 处理命令输入框的键盘事件
        const handleCommandInputKeydown = (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                const command = currentCommandInput.value;

                currentCommandInput.setAttribute('readonly', true);
                currentCommandInput.style.pointerEvents = 'none';

                const displayedCommand = (currentCommandInput.type === 'password') ? '*'.repeat(command.length) : command;
                const currentPrompt = currentCommandInput.previousElementSibling.textContent;
                terminalOutput.lastElementChild.innerHTML = `<span class="prompt">${currentPrompt}</span>${displayedCommand}`;
                
                if (command.trim() === '' && state.isAuthenticated) {
                    renderPromptAndInput(currentPrompt);
                    return;
                }

                executeCommand(command);

            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (historyIndex > 0) {
                    historyIndex--;
                    currentCommandInput.value = commandHistory[historyIndex];
                }
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (historyIndex < commandHistory.length - 1) {
                    historyIndex++;
                    currentCommandInput.value = commandHistory[historyIndex];
                } else if (historyIndex === commandHistory.length - 1) {
                    historyIndex++;
                    currentCommandInput.value = '';
                }
            }
        };

        // 执行命令
        const executeCommand = (command) => {
            if (command.trim() !== '' && commandHistory[commandHistory.length - 1] !== command) {
                commandHistory.push(command);
            }
            historyIndex = commandHistory.length;

            const formData = new URLSearchParams();
            formData.append('command', command);
            // 迭代发送JS维护的所有状态
            for (const key in state) {
                formData.append(key, state[key]);
            }

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString(),
            })
            .then(response => response.json())
            .then(data => {
                // 从PHP响应中更新JS维护的状态
                Object.assign(state, {
                    isAuthenticated: data.isAuthenticated,
                    authStep: data.authStep,
                    tempUsername: data.tempUsername,
                    cwd: data.cwd
                });

                if (data.output === '[[CLEAR_SCREEN]]') {
                    terminalOutput.innerHTML = '';
                } else {
                    const outputDiv = document.createElement('div');
                    outputDiv.innerHTML = data.output ? data.output.replace(/\n/g, '<br>') : '';
                    if (data.output && !data.output.endsWith('\n')) {
                         outputDiv.innerHTML += '<br>';
                    }
                    terminalOutput.appendChild(outputDiv);
                }
                renderPromptAndInput(data.prompt);
            })
            .catch(error => {
                const errorDiv = document.createElement('div');
                errorDiv.innerHTML = `<span style="color: red;">Error: Could not connect to server or parse response.</span><br>`;
                terminalOutput.appendChild(errorDiv);
                console.error('Fetch error:', error);
                renderPromptAndInput(`error@terminal:${state.cwd}$ `); // 尝试使用当前CWD
            });
        };

        // 页面加载时渲染初始提示符和输入框
        window.addEventListener('load', () => {
            renderPromptAndInput(initialState.prompt);
        });

        // 确保在点击页面其他地方后，输入框仍能获得焦点
        document.addEventListener('click', (event) => {
            if (currentCommandInput && event.target !== currentCommandInput) {
                currentCommandInput.focus();
            }
        });

        // 监听虚拟键盘弹出/收起，调整滚动位置
        window.addEventListener('resize', scrollToBottom);
    </script>
</body>
</html>