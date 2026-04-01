(function () {
  'use strict';

  const root = document.querySelector('[data-tagging-root]');
  if (!root) {
    return;
  }

  const sheetId = root.getAttribute('data-sheet-id') || '';
  if (!sheetId) {
    return;
  }

  const apiUrl = 'customer_tagging_api.php';
  const tagSelect = root.querySelector('[data-tag-select]');
  const addButton = root.querySelector('[data-tag-add]');
  const badgesWrap = root.querySelector('[data-tag-badges]');
  const managerModal = document.getElementById('tag-manager-modal');
  const managerList = managerModal ? managerModal.querySelector('[data-tag-manager-list]') : null;
  const managerNameInput = managerModal ? managerModal.querySelector('[data-tag-manager-name]') : null;
  const managerColorInput = managerModal ? managerModal.querySelector('[data-tag-manager-color]') : null;
  const managerActionInput = managerModal ? managerModal.querySelector('[data-tag-manager-action]') : null;
  const managerTagIdInput = managerModal ? managerModal.querySelector('[data-tag-manager-tag-id]') : null;
  const managerSubmitLabel = managerModal ? managerModal.querySelector('[data-tag-manager-submit-label]') : null;
  const managerCancelEdit = managerModal ? managerModal.querySelector('[data-tag-manager-cancel-edit]') : null;
  const managerForm = managerModal ? managerModal.querySelector('[data-tag-manager-form]') : null;

  let tags = [];
  let assignedTags = [];
  const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

  const request = async (params) => {
    const response = await window.fetch(apiUrl, params);
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.message || 'タグの保存に失敗しました。');
    }
    return data;
  };

  const resetManagerForm = () => {
    if (!managerActionInput || !managerTagIdInput || !managerNameInput || !managerColorInput || !managerSubmitLabel) {
      return;
    }
    managerActionInput.value = 'create_tag';
    managerTagIdInput.value = '';
    managerNameInput.value = '';
    managerColorInput.value = '#3b82f6';
    managerSubmitLabel.textContent = '追加';
    if (managerCancelEdit) {
      managerCancelEdit.hidden = true;
    }
  };

  const renderSelectOptions = () => {
    if (!tagSelect) {
      return;
    }
    const assignedIds = new Set(assignedTags.map((item) => String(item.id)));
    const options = ['<option value="">タグを選択</option>'];
    tags.forEach((tag) => {
      if (assignedIds.has(String(tag.id))) {
        return;
      }
      options.push(`<option value="${String(tag.id)}">${escapeHtml(String(tag.name))}</option>`);
    });
    tagSelect.innerHTML = options.join('');
  };

  const renderBadges = () => {
    if (!badgesWrap) {
      return;
    }
    if (assignedTags.length === 0) {
      badgesWrap.innerHTML = '<p class="tagging-empty">タグは未設定です。</p>';
      return;
    }

    badgesWrap.innerHTML = assignedTags.map((tag) => {
      const color = String(tag.color || '#3b82f6');
      const safeName = escapeHtml(String(tag.name || 'タグ'));
      return `
        <span class="tag-badge" style="--tag-color:${color}">
          <span class="tag-badge-label">${safeName}</span>
          <button type="button" class="tag-badge-remove" data-remove-tag-id="${String(tag.id)}" aria-label="${safeName} を削除">×</button>
        </span>
      `;
    }).join('');
  };

  const renderManagerList = () => {
    if (!managerList) {
      return;
    }
    if (tags.length === 0) {
      managerList.innerHTML = '<p class="tagging-empty">タグがありません。</p>';
      return;
    }
    managerList.innerHTML = tags.map((tag) => {
      const color = String(tag.color || '#3b82f6');
      const name = escapeHtml(String(tag.name || 'タグ'));
      return `
        <li class="tag-manager-item">
          <span class="tag-preview" style="--tag-color:${color}">${name}</span>
          <div class="tag-manager-actions">
            <button type="button" class="btn btn-ghost" data-tag-edit-id="${String(tag.id)}">編集</button>
            <button type="button" class="btn btn-ghost btn-danger" data-tag-delete-id="${String(tag.id)}">削除</button>
          </div>
        </li>
      `;
    }).join('');
  };

  const rerender = () => {
    renderSelectOptions();
    renderBadges();
    renderManagerList();
  };

  const syncState = (payload) => {
    if (Array.isArray(payload.tags)) {
      tags = payload.tags;
    }
    if (Array.isArray(payload.assigned_tags)) {
      assignedTags = payload.assigned_tags;
    }
    rerender();
  };

  const fetchList = async () => {
    const url = `${apiUrl}?action=list&sheet_id=${encodeURIComponent(sheetId)}`;
    const response = await window.fetch(url, { cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.message || 'タグの取得に失敗しました。');
    }
    syncState(data);
  };

  if (addButton && tagSelect) {
    addButton.addEventListener('click', async () => {
      const tagId = Number(tagSelect.value || 0);
      if (!tagId) {
        window.alert('追加するタグを選択してください。');
        return;
      }

      addButton.disabled = true;
      try {
        const body = new URLSearchParams({
          action: 'assign',
          sheet_id: sheetId,
          tag_id: String(tagId),
        });
        const data = await request({
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          },
          body,
        });
        syncState(data);
      } catch (error) {
        window.alert(error.message || 'タグ付けに失敗しました。');
      } finally {
        addButton.disabled = false;
      }
    });
  }

  if (badgesWrap) {
    badgesWrap.addEventListener('click', async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }
      const removeId = Number(target.getAttribute('data-remove-tag-id') || 0);
      if (!removeId) {
        return;
      }

      target.setAttribute('disabled', 'disabled');
      try {
        const body = new URLSearchParams({
          action: 'unassign',
          sheet_id: sheetId,
          tag_id: String(removeId),
        });
        const data = await request({
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          },
          body,
        });
        syncState(data);
      } catch (error) {
        window.alert(error.message || 'タグ解除に失敗しました。');
        target.removeAttribute('disabled');
      }
    });
  }

  if (managerForm) {
    managerForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!managerActionInput || !managerNameInput || !managerColorInput) {
        return;
      }

      const action = managerActionInput.value || 'create_tag';
      const body = new URLSearchParams({
        action,
        name: managerNameInput.value,
        color: managerColorInput.value,
      });
      if (action === 'update_tag' && managerTagIdInput && managerTagIdInput.value) {
        body.set('tag_id', managerTagIdInput.value);
      }

      try {
        const data = await request({
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          },
          body,
        });
        tags = Array.isArray(data.tags) ? data.tags : tags;
        resetManagerForm();
        await fetchList();
      } catch (error) {
        window.alert(error.message || 'タグ管理操作に失敗しました。');
      }
    });
  }

  if (managerCancelEdit) {
    managerCancelEdit.addEventListener('click', () => {
      resetManagerForm();
    });
  }

  if (managerList) {
    managerList.addEventListener('click', async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }

      const editId = Number(target.getAttribute('data-tag-edit-id') || 0);
      if (editId) {
        const found = tags.find((item) => Number(item.id) === editId);
        if (!found || !managerActionInput || !managerTagIdInput || !managerNameInput || !managerColorInput || !managerSubmitLabel) {
          return;
        }
        managerActionInput.value = 'update_tag';
        managerTagIdInput.value = String(found.id);
        managerNameInput.value = String(found.name || '');
        managerColorInput.value = String(found.color || '#3b82f6');
        managerSubmitLabel.textContent = '更新';
        if (managerCancelEdit) {
          managerCancelEdit.hidden = false;
        }
        return;
      }

      const deleteId = Number(target.getAttribute('data-tag-delete-id') || 0);
      if (!deleteId) {
        return;
      }

      if (!window.confirm('このタグを削除しますか？関連するタグ付けも解除されます。')) {
        return;
      }

      try {
        const body = new URLSearchParams({
          action: 'delete_tag',
          tag_id: String(deleteId),
        });
        await request({
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          },
          body,
        });
        resetManagerForm();
        await fetchList();
      } catch (error) {
        window.alert(error.message || 'タグ削除に失敗しました。');
      }
    });
  }

  document.querySelectorAll('[data-open-modal="tag-manager-modal"]').forEach((button) => {
    button.addEventListener('click', () => {
      resetManagerForm();
    });
  });

  fetchList().catch(() => {
    window.alert('タグ情報の取得に失敗しました。');
  });
})();
