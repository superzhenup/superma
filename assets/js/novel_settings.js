// 小说详情页（novel.php）：小说设置。由 novel.php 内联脚本原样外置。
function getCsrf() { return window.CSRF_TOKEN || ''; }

// ================================================================
// v1.11.8: 小说设置编辑
// ================================================================

function editTargetChapters() {
  document.getElementById('display-target-chapters').classList.add('d-none');
  document.getElementById('edit-target-chapters').classList.remove('d-none');
  document.getElementById('input-target-chapters').focus();
}

function cancelEditTargetChapters() {
  document.getElementById('edit-target-chapters').classList.add('d-none');
  document.getElementById('display-target-chapters').classList.remove('d-none');
}

async function saveTargetChapters() {
  const value = parseInt(document.getElementById('input-target-chapters').value);
  if (value < 1 || value > 10000) {
    alert('目标章节数必须在 1-10000 之间');
    return;
  }

  try {
    const res = await apiPost('actions', {
      action: 'update_novel_settings',
      novel_id: NOVEL_ID,
      target_chapters: value
    });

    if (res.ok) {
      document.getElementById('display-target-chapters').textContent = value + ' 章';
      cancelEditTargetChapters();
      // 更新全局变量
      window.TARGET_CHS = value;
      alert('目标章节数已更新为 ' + value + ' 章');
    } else {
      alert(res.error || '保存失败');
    }
  } catch (e) {
    alert('保存失败：' + e.message);
  }
}

function editChapterWords() {
  document.getElementById('display-chapter-words').classList.add('d-none');
  document.getElementById('edit-chapter-words').classList.remove('d-none');
  document.getElementById('input-chapter-words').focus();
}

function cancelEditChapterWords() {
  document.getElementById('edit-chapter-words').classList.add('d-none');
  document.getElementById('display-chapter-words').classList.remove('d-none');
}

async function saveChapterWords() {
  const value = parseInt(document.getElementById('input-chapter-words').value);
  if (value < 500 || value > 20000) {
    alert('每章字数必须在 500-20000 之间');
    return;
  }

  try {
    const res = await apiPost('actions', {
      action: 'update_novel_settings',
      novel_id: NOVEL_ID,
      chapter_words: value
    });

    if (res.ok) {
      document.getElementById('display-chapter-words').textContent = value.toLocaleString() + ' 字';
      cancelEditChapterWords();
      alert('每章字数已更新为 ' + value.toLocaleString() + ' 字');
    } else {
      alert(res.error || '保存失败');
    }
  } catch (e) {
    alert('保存失败：' + e.message);
  }
}

