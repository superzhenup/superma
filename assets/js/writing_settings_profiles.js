// 写作设置页（writing_settings.php）：作者画像管理与分析。由内联脚本原样外置。
function showCreateProfileModal() {
    new bootstrap.Modal(document.getElementById('createProfileModal')).show();
}

document.getElementById('createProfileForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const btn = this.querySelector('button[type="submit"]');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>创建中...';
    fetch(apiRouteUrl('author_profile', 'action=create'), {
        method: 'POST',
        body: formData
    }).then(res => {
        if (!res.ok) {
            return res.text().then(text => {
                let msg = '创建失败';
                try { msg = JSON.parse(text).message || JSON.parse(text).error || msg; } catch(e2) { msg = text.substring(0, 200); }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(data => {
        if (data.success) {
            window.location.href = `?tab=profile&action=edit&id=${data.data.id}`;
        } else {
            alert(data.message || data.error || '创建失败');
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }).catch(err => {
        console.error('创建画像失败:', err);
        alert('创建失败: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = origText;
    });
});

function deleteProfile(id) {
    if (!confirm('确定删除该作者画像？')) return;
    fetch(apiRouteUrl('author_profile', `action=profile&id=${encodeURIComponent(id)}`), {
        method: 'DELETE'
    }).then(res => {
        if (!res.ok) {
            return res.text().then(text => {
                let msg = '删除失败';
                try { 
                    let json = JSON.parse(text);
                    msg = json.message || json.error || msg;
                } catch(e2) { 
                    if (text.includes('<br')) {
                        let match = text.match(/<b>([^<]+)<\/b>/);
                        msg = match ? match[1] : msg;
                    } else {
                        msg = text.substring(0, 100);
                    }
                }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || data.error || '删除失败');
        }
    }).catch(err => {
        console.error('删除画像失败:', err);
        alert('删除失败: ' + err.message);
    });
}

document.querySelector('button[onclick*="reset"]')?.addEventListener('click', function(e) {
    e.preventDefault();
    if (!confirm('确定将所有写作参数重置为系统默认值？')) return;
    var form = document.getElementById('ws-form');
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'action';
    input.value = 'reset';
    form.appendChild(input);
    form.submit();
});

document.getElementById('editProfileForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const profileId = form.querySelector('[name="profile_id"]').value;
    const btn = form.querySelector('button[type="submit"]');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>保存中...';
    const data = {};
    new FormData(form).forEach((v, k) => { if (k !== 'profile_id') data[k] = v; });
    fetch(apiRouteUrl('author_profile', `action=profile&id=${encodeURIComponent(profileId)}`), {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(res => {
        if (!res.ok) {
            return res.text().then(text => {
                let msg = '保存失败';
                try { msg = JSON.parse(text).message || JSON.parse(text).error || msg; } catch(e2) { msg = text.substring(0, 200); }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(data => {
        alert('保存成功');
        btn.disabled = false;
        btn.innerHTML = origText;
    }).catch(err => {
        console.error('保存失败:', err);
        alert('保存失败: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = origText;
    });
});

function uploadWorkFile(profileId) {
    var fileInput = document.getElementById('workFileInput');
    if (!fileInput || !fileInput.files.length) {
        alert('请先选择文件');
        return;
    }
    var file = fileInput.files[0];
    var btn = document.getElementById('uploadFileBtn');
    var origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>上传中...';

    var progressDiv = document.getElementById('uploadProgress');
    var progressBar = document.getElementById('uploadProgressBar');
    var statusText = document.getElementById('uploadStatusText');
    var resultDiv = document.getElementById('uploadResult');
    var resultAlert = document.getElementById('uploadResultAlert');
    progressDiv.style.display = '';
    resultDiv.style.display = 'none';
    progressBar.style.width = '10%';
    progressBar.textContent = '10%';
    statusText.textContent = '正在上传文件...';

    var fd = new FormData();
    fd.append('file', file);
    fd.append('profile_id', profileId);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', apiRouteUrl('author_profile', 'action=upload'), true);

    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            var pct = Math.round(e.loaded / e.total * 70);
            progressBar.style.width = pct + '%';
            progressBar.textContent = pct + '%';
        }
    };

    xhr.onload = function() {
        var data;
        try { data = JSON.parse(xhr.responseText); } catch(e) {
            progressBar.style.width = '100%';
            progressBar.textContent = '100%';
            statusText.textContent = '上传完成，但服务器返回异常';
            resultDiv.style.display = '';
            resultAlert.className = 'alert alert-danger';
            resultAlert.textContent = '服务器返回异常，请重试';
            btn.disabled = false;
            btn.innerHTML = origHtml;
            return;
        }

        if (data.success) {
            progressBar.style.width = '80%';
            progressBar.textContent = '80%';
            statusText.textContent = '文件上传成功！共 ' + (data.chapter_count || 0) + ' 章，' + (data.char_count || 0) + ' 字。正在启动风格分析...';
            startAnalysis(profileId, data.work_id, progressBar, statusText, resultDiv, resultAlert, btn, origHtml);
        } else {
            progressBar.style.width = '100%';
            progressBar.textContent = '100%';
            statusText.textContent = '上传失败';
            resultDiv.style.display = '';
            resultAlert.className = 'alert alert-danger';
            resultAlert.textContent = data.error || data.message || '上传失败';
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    };

    xhr.onerror = function() {
        statusText.textContent = '网络错误';
        resultDiv.style.display = '';
        resultAlert.className = 'alert alert-danger';
        resultAlert.textContent = '网络请求失败，请重试';
        btn.disabled = false;
        btn.innerHTML = origHtml;
    };

    xhr.send(fd);
}

function analyzeFromText(profileId) {
    var textarea = document.getElementById('workTextInput');
    if (!textarea) return;
    var text = textarea.value.trim();
    if (text.length < 1000) {
        alert('文本太短，至少需要1000字进行分析');
        return;
    }
    var progressDiv = document.getElementById('uploadProgress');
    var progressBar = document.getElementById('uploadProgressBar');
    var statusText = document.getElementById('uploadStatusText');
    var resultDiv = document.getElementById('uploadResult');
    var resultAlert = document.getElementById('uploadResultAlert');
    progressDiv.style.display = '';
    resultDiv.style.display = 'none';
    progressBar.style.width = '20%';
    progressBar.textContent = '20%';
    statusText.textContent = '正在提交文本进行分析...';

    fetch(apiRouteUrl('author_profile', 'action=analyze-text'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ profile_id: profileId, text: text })
    }).then(function(res) {
        if (!res.ok) {
            return res.text().then(function(text) {
                var msg = '分析失败';
                try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(function(data) {
        if (data.success) {
            progressBar.style.width = '100%';
            progressBar.textContent = '100%';
            progressBar.className = 'progress-bar bg-success';
            statusText.textContent = '风格分析完成！';
            resultDiv.style.display = '';
            resultAlert.className = 'alert alert-success';
            resultAlert.innerHTML = '风格分析完成！<a href="?tab=profile&action=edit&id=' + profileId + '" class="alert-link">点击刷新查看结果</a>';
        } else {
            throw new Error(data.error || data.message || '分析失败');
        }
    }).catch(function(err) {
        progressBar.style.width = '100%';
        progressBar.textContent = '100%';
        progressBar.className = 'progress-bar bg-danger';
        statusText.textContent = '分析失败';
        resultDiv.style.display = '';
        resultAlert.className = 'alert alert-danger';
        resultAlert.textContent = err.message;
    });
}

function startAnalysis(profileId, workId, progressBar, statusText, resultDiv, resultAlert, btn, origHtml) {
    progressBar.style.width = '85%';
    progressBar.textContent = '85%';
    statusText.textContent = '正在进行风格分析（可能需要数十秒）...';

    fetch(apiRouteUrl('author_profile', 'action=analyze'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ profile_id: profileId, work_id: workId })
    }).then(function(res) {
        if (!res.ok) {
            return res.text().then(function(text) {
                var msg = '分析失败';
                try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(function(data) {
        if (data.success) {
            progressBar.style.width = '95%';
            progressBar.textContent = '95%';
            statusText.textContent = '正在加载分析结果...';
            fetchProfileAndPopulatePrompts(profileId, function() {
                progressBar.style.width = '100%';
                progressBar.textContent = '100%';
                progressBar.className = 'progress-bar bg-success';
                statusText.textContent = '风格分析完成！';
                resultDiv.style.display = '';
                resultAlert.className = 'alert alert-success';
                resultAlert.innerHTML = '风格分析完成！<a href="?tab=profile&action=edit&id=' + profileId + '" class="alert-link">点击刷新查看结果</a>';
                btn.disabled = false;
                btn.innerHTML = origHtml;
            });
        } else {
            throw new Error(data.error || data.message || '分析失败');
        }
    }).catch(function(err) {
        progressBar.style.width = '100%';
        progressBar.textContent = '100%';
        progressBar.className = 'progress-bar bg-danger';
        statusText.textContent = '分析失败';
        resultDiv.style.display = '';
        resultAlert.className = 'alert alert-danger';
        resultAlert.textContent = '分析失败: ' + err.message;
        btn.disabled = false;
        btn.innerHTML = origHtml;
    });
}

function fetchProfileAndPopulatePrompts(profileId, callback) {
    fetch(apiRouteUrl('author_profile', 'action=profile&id=' + encodeURIComponent(profileId)))
        .then(function(res) { return res.json(); })
        .then(function(profile) {
            var prompts = [
                'writing_habits_prompt',
                'narrative_style_prompt',
                'sentiment_prompt',
                'creative_identity_prompt'
            ];
            prompts.forEach(function(prompt) {
                var textarea = document.getElementById('textarea-' + prompt.replace('_prompt', ''));
                if (textarea && profile[prompt]) {
                    var value = typeof profile[prompt] === 'object'
                        ? JSON.stringify(profile[prompt], null, 2)
                        : profile[prompt];
                    textarea.value = value;
                }
            });
            if (callback) callback();
        })
        .catch(function(err) {
            console.error('Failed to load profile prompts:', err);
            if (callback) callback();
        });
}

function analyzeDimension(profileId, dimension) {
    const btn = document.getElementById('btn-' + dimension);
    if (btn.classList.contains('running')) return;
    
    btn.classList.add('running');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 分析中...';

    fetch(apiRouteUrl('author_profile', 'action=analyze-dimension'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ profile_id: profileId, dimension: dimension })
    }).then(res => {
        if (!res.ok) {
            return res.text().then(text => {
                let msg = '分析失败';
                try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                throw new Error(msg);
            });
        }
        return res.json();
    }).then(data => {
        if (data.success) {
            btn.classList.remove('running');
            btn.disabled = false;
            btn.innerHTML = getDimensionLabel(dimension) + ' <span class="badge bg-success ms-1">✓</span>';
            updateProgress(data.progress);
            alert(dimensionLabels[dimension] + ' 分析完成！');
            window.location.reload();
        } else {
            throw new Error(data.error || data.message || '分析失败');
        }
    }).catch(err => {
        btn.classList.remove('running');
        btn.disabled = false;
        btn.innerHTML = getDimensionLabel(dimension) + ' <span class="badge bg-danger ms-1">✗</span>';
        alert('分析失败: ' + err.message);
    });
}

function runAllDimensions(profileId) {
    const dimensions = ['writing_habits', 'narrative_style', 'sentiment', 'creative_identity'];
    let currentIndex = 0;
    
    function runNext() {
        if (currentIndex >= dimensions.length) {
            alert('所有维度分析完成！');
            window.location.reload();
            return;
        }
        
        const dimension = dimensions[currentIndex];
        const btn = document.getElementById('btn-' + dimension);
        btn.classList.add('running');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 分析中...';

        fetch(apiRouteUrl('author_profile', 'action=analyze-dimension'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ profile_id: profileId, dimension: dimension })
        }).then(res => {
            if (!res.ok) {
                return res.text().then(text => {
                    let msg = '分析失败';
                    try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                    throw new Error(msg);
                });
            }
            return res.json();
        }).then(data => {
            if (data.success) {
                btn.classList.remove('running');
                btn.disabled = false;
                btn.innerHTML = getDimensionLabel(dimension) + ' <span class="badge bg-success ms-1">✓</span>';
                updateProgress(data.progress);
                currentIndex++;
                runNext();
            } else {
                throw new Error(data.error || data.message || '分析失败');
            }
        }).catch(err => {
            btn.classList.remove('running');
            btn.disabled = false;
            btn.innerHTML = getDimensionLabel(dimension) + ' <span class="badge bg-danger ms-1">✗</span>';
            alert('分析失败: ' + err.message);
        });
    }
    
    runNext();
}

function updateProgress(progress) {
    const bar = document.getElementById('analysisProgressBar');
    const text = document.getElementById('analysisProgressText');
    if (bar && text) {
        const pct = Math.round((progress.completed / progress.total) * 100);
        bar.style.width = pct + '%';
        text.textContent = progress.completed + '/' + progress.total + ' 维度已完成';
    }
}

const dimensionLabels = {
    writing_habits: '写作习惯',
    narrative_style: '叙事手法',
    sentiment: '思想情感',
    creative_identity: '创作个性'
};

const LABELS = {
    pov: { first_person: '第一人称', second_person: '第二人称', third_limited: '第三人称限视', third_omniscient: '第三人称全知', multiple: '多视角' },
    tone: { optimistic: '积极乐观', pessimistic: '消极悲观', neutral: '中立客观', bittersweet: '苦乐参半', dark: '暗黑压抑', uplifting: '振奋人心' },
    tension: { peak: '高潮', rising: '上升', neutral: '中性', falling: '下降', low: '低谷' },
    tensionColors: { peak: 'danger', rising: 'warning', neutral: 'secondary', falling: 'info', low: 'primary' },
    emotion: { joy: '喜悦', sadness: '悲伤', anger: '愤怒', fear: '恐惧', surprise: '惊讶', love: '爱', disgust: '厌恶' },
    emotionColors: { joy: 'warning', sadness: 'info', anger: 'danger', fear: 'secondary', surprise: 'success', love: 'danger', disgust: 'dark' },
    beautyFocus: { nature: '自然景物', character: '人物外貌', architecture: '建筑场景', action: '动作场面', emotion: '情感渲染' },
    valueTendency: { success: '成功荣耀', love: '真爱守护', freedom: '自由解放', justice: '正义公道', family: '家族传承' },
    characterArchetype: { hero: '英雄主角', mentor: '导师角色', villain: '反派BOSS', love_interest: '恋人角色', comic_relief: '喜剧角色', lancer: '搭档角色' },
    rhetoric: { metaphor: '暗喻', simile: '明喻', personification: '拟人', hyperbole: '夸张', parallelism: '排比', repetition: '反复' }
};

function getDimensionLabel(dimension) {
    const icons = {
        writing_habits: '<i class="bi bi-pen"></i>',
        narrative_style: '<i class="bi bi-eye"></i>',
        sentiment: '<i class="bi bi-heart"></i>',
        creative_identity: '<i class="bi bi-stars"></i>'
    };
    return icons[dimension] + ' ' + dimensionLabels[dimension];
}

function toggleDimensionEdit(dimension) {
    const viewDiv = document.getElementById(dimension + '_view');
    const editDiv = document.getElementById(dimension + '_edit');
    if (viewDiv && editDiv) {
        const isEditing = editDiv.style.display !== 'none';
        viewDiv.style.display = isEditing ? '' : 'none';
        editDiv.style.display = isEditing ? 'none' : '';
    }
}

function saveDimension(profileId, dimension) {
    const textarea = document.getElementById('textarea-' + dimension);
    if (!textarea) return;
    
    const inputValue = textarea.value.trim();
    if (!inputValue) {
        alert('请输入内容');
        return;
    }

    let resultData;
    let isJson = false;
    try {
        resultData = JSON.parse(inputValue);
        isJson = true;
    } catch(e) {
        resultData = inputValue;
    }

    const promptFieldMap = {
        'writing_habits': 'writing_habits_prompt',
        'narrative_style': 'narrative_style_prompt',
        'sentiment': 'sentiment_prompt',
        'creative_identity': 'creative_identity_prompt'
    };
    const promptField = promptFieldMap[dimension];

    Promise.all([
        fetch(apiRouteUrl('author_profile', `action=dimension&dimension=${encodeURIComponent(dimension)}&profile_id=${encodeURIComponent(profileId)}`), {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ result: resultData })
        }).then(res => {
            if (!res.ok) {
                return res.text().then(text => {
                    let msg = '分析数据保存失败';
                    try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                    throw new Error(msg);
                });
            }
            return res.json();
        }),
        fetch(apiRouteUrl('author_profile', `action=profile&id=${encodeURIComponent(profileId)}`), {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ [promptField]: inputValue })
        }).then(res => {
            if (!res.ok) {
                return res.text().then(text => {
                    let msg = '提示词保存失败';
                    try { msg = JSON.parse(text).error || JSON.parse(text).message || msg; } catch(e2) { msg = text.substring(0, 200); }
                    throw new Error(msg);
                });
            }
            return res.json();
        })
    ]).then(function(results) {
        alert(dimensionLabels[dimension] + ' 保存成功！');
        toggleDimensionEdit(dimension);
        window.location.reload();
    }).catch(function(err) {
        alert('保存失败: ' + err.message);
    });
}
