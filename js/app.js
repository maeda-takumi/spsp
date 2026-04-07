(function () {
  'use strict';

  const form = document.querySelector('[data-filter-form]');
  if (form) {
    const statusField = form.querySelector('select[name="status"]');
    if (statusField) {
      statusField.addEventListener('change', () => {
        statusField.style.borderColor = statusField.value ? 'rgba(37,99,235,.45)' : 'rgba(15,23,42,.08)';
      });
    }
  }

  const mailTemplate = document.querySelector('[data-mail-template]');
  const mailSubjectInput = document.querySelector('[data-mail-subject-input]');
  const mailBodyInput = document.querySelector('[data-mail-body-input]');
  if (mailTemplate && mailSubjectInput && mailBodyInput) {
    const applyTemplate = () => {
      const selected = mailTemplate.options[mailTemplate.selectedIndex];
      if (!selected) {
        return;
      }
      const subject = selected.getAttribute('data-mail-subject') || '';
      const body = selected.getAttribute('data-mail-body') || '';
      mailSubjectInput.value = subject;
      mailBodyInput.value = body;
    };

    mailTemplate.addEventListener('change', applyTemplate);
  }
  const importSheetButton = document.querySelector('[data-run-import-sheet]');
  const importCompletedAtNode = document.querySelector('[data-import-completed-at]');
  const globalLoadingOverlay = document.querySelector('[data-global-loading-overlay]');
  const IMPORT_COMPLETED_AT_STORAGE_KEY = 'import_completed_at';

  const updateImportCompletedAtText = (completedAt) => {
    if (!importCompletedAtNode) {
      return;
    }

    if (completedAt) {
      importCompletedAtNode.textContent = `最終取込: ${completedAt}`;
      return;
    }

    importCompletedAtNode.textContent = '最終取込: 未実行';
  };

  const getStoredImportCompletedAt = () => {
    try {
      return window.localStorage.getItem(IMPORT_COMPLETED_AT_STORAGE_KEY) || '';
    } catch (error) {
      return '';
    }
  };

  const saveImportCompletedAt = (completedAt) => {
    if (!completedAt) {
      return;
    }

    try {
      window.localStorage.setItem(IMPORT_COMPLETED_AT_STORAGE_KEY, completedAt);
    } catch (error) {
      // localStorageが利用できない場合は表示更新のみ行う
    }
  };

  const syncImportCompletedAt = () => {
    const url = new URL(window.location.href);
    const fromQuery = (url.searchParams.get('import_completed_at') || '').trim();
    if (fromQuery) {
      saveImportCompletedAt(fromQuery);
      updateImportCompletedAtText(fromQuery);
      return;
    }

    const storedValue = getStoredImportCompletedAt();
    if (storedValue) {
      updateImportCompletedAtText(storedValue);
    }
  };

  syncImportCompletedAt();

  const formatImportedAt = (date) => {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    const hh = String(date.getHours()).padStart(2, '0');
    const mm = String(date.getMinutes()).padStart(2, '0');
    const ss = String(date.getSeconds()).padStart(2, '0');
    return `${y}-${m}-${d} ${hh}:${mm}:${ss}`;
  };
  if (importSheetButton) {
    importSheetButton.addEventListener('click', async () => {
      importSheetButton.disabled = true;
      if (globalLoadingOverlay) {
        globalLoadingOverlay.hidden = false;
      }
      try {
        const response = await window.fetch('import_sheet_to_db.php', {
          method: 'GET',
          cache: 'no-store',
        });
        const responseText = await response.text();
        if (!response.ok) {
          throw new Error(`status=${response.status} body=${responseText}`);
        }
        if (!responseText.includes('Import completed.')) {
          throw new Error(`unexpected response=${responseText}`);
        }
        const completedAt = formatImportedAt(new Date());
        saveImportCompletedAt(completedAt);
        updateImportCompletedAtText(completedAt);

        const url = new URL(window.location.href);
        url.searchParams.set('import_completed_at', completedAt);
        window.location.href = url.toString();
      } catch (error) {
        window.alert('DB取り込みに失敗しました。時間をおいて再度お試しください。');
        importSheetButton.disabled = false;
        if (globalLoadingOverlay) {
          globalLoadingOverlay.hidden = true;
        }
      }
    });
  }

  const copyButtons = document.querySelectorAll('[data-copy-value]');
  if (copyButtons.length > 0) {
    copyButtons.forEach((button) => {
      button.addEventListener('click', async () => {
        const value = button.getAttribute('data-copy-value') || '';
        try {
          await navigator.clipboard.writeText(value);
          window.alert('クリップボードに保存しました');
        } catch (error) {
          window.alert('クリップボードへの保存に失敗しました');
        }
      });
    });
  }
  const sectionToggleButtons = document.querySelectorAll('[data-section-toggle]');
  if (sectionToggleButtons.length > 0) {
    const OPEN_ICON_SRC = 'img/open.png';
    const CLOSE_ICON_SRC = 'img/close.png';
    const ICON_ANIMATION_MS = 180;
    const BODY_ANIMATION_MS = 320;

    const setToggleState = (button, expanded, animate = true) => {
      const icon = button.querySelector('[data-toggle-icon]');
      button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      button.setAttribute('aria-label', expanded ? '閉じる' : '開ける');
      if (icon) {
        if (!animate) {
          icon.setAttribute('src', expanded ? CLOSE_ICON_SRC : OPEN_ICON_SRC);
          return;
        }
        button.classList.add('is-icon-changing');
        window.setTimeout(() => {
          icon.setAttribute('src', expanded ? CLOSE_ICON_SRC : OPEN_ICON_SRC);
          button.classList.remove('is-icon-changing');
        }, ICON_ANIMATION_MS);
      }
    };

    const animateSection = (button, target, willExpand) => {
      const panel = button.closest('.detail-panel');
      if (willExpand) {
        target.hidden = false;
        target.classList.remove('is-collapsed');
        target.style.maxHeight = '0px';
        void target.offsetHeight;
        target.style.maxHeight = `${target.scrollHeight}px`;
        if (panel) {
          panel.classList.remove('is-collapsed');
        }
        window.setTimeout(() => {
          target.style.maxHeight = '';
        }, BODY_ANIMATION_MS);
        return;
      }

      target.style.maxHeight = `${target.scrollHeight}px`;
      void target.offsetHeight;
      target.classList.add('is-collapsed');
      target.style.maxHeight = '0px';
      if (panel) {
        panel.classList.add('is-collapsed');
      }
      window.setTimeout(() => {
        target.hidden = true;
        target.style.maxHeight = '';
      }, BODY_ANIMATION_MS);
    };
    sectionToggleButtons.forEach((button) => {
      const targetId = button.getAttribute('data-target-id');
      if (!targetId) {
        return;
      }
      const target = document.getElementById(targetId);
      if (!target) {
        return;
      }
      const isExpanded = !target.hidden;
      setToggleState(button, isExpanded, false);
      if (!isExpanded) {
        target.classList.add('is-collapsed');
        const panel = button.closest('.detail-panel');
        if (panel) {
          panel.classList.add('is-collapsed');
        }
      }

      button.addEventListener('click', () => {
        const willExpand = target.hidden;
        animateSection(button, target, willExpand);
        setToggleState(button, willExpand);
      });
    });
  }

  const sidebarAvatar = document.querySelector('[data-sidebar-avatar]');
  if (sidebarAvatar) {
    const AVATAR_FADE_DURATION_MS = 360;
    const NORMAL_IMAGE_SRC = 'img/human.png';
    const GOLD_IMAGE_SRC = 'img/human_gold.png';
    const RAINBOW_IMAGE_SRC = 'img/human_rainbow.png';
    let isAvatarAnimating = false;


    const preloadImage = (src) => {
      const image = new Image();
      image.src = src;
    };

    preloadImage(NORMAL_IMAGE_SRC);
    preloadImage(GOLD_IMAGE_SRC);
    preloadImage(RAINBOW_IMAGE_SRC);

    const pickAvatarImage = () => {
      const roll = Math.random();
      if (roll < 0.7) {
        return NORMAL_IMAGE_SRC;
      }
      if (roll < 0.9) {
        return GOLD_IMAGE_SRC;
      }
      return RAINBOW_IMAGE_SRC;
    };

    sidebarAvatar.addEventListener('click', () => {
      if (isAvatarAnimating) {
        return;
      }

      isAvatarAnimating = true;
      const nextImageSrc = pickAvatarImage();
      sidebarAvatar.classList.add('is-fading-out');

      window.setTimeout(() => {
        sidebarAvatar.setAttribute('src', nextImageSrc);
        sidebarAvatar.classList.remove('is-fading-out');
        isAvatarAnimating = false;
      }, AVATAR_FADE_DURATION_MS);
    });
  }
  const openButtons = document.querySelectorAll('[data-open-modal]');
  if (openButtons.length === 0) {
    return;
  }

  const closeModal = (modal) => {
    if (!modal) {
      return;
    }
    modal.hidden = true;
  };

  openButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const modalId = button.getAttribute('data-open-modal');
      if (!modalId) {
        return;
      }

      const modal = document.getElementById(modalId);
      if (!modal) {
        return;
      }

      if (modalId === 'writing-modal') {
        const writing = button.getAttribute('data-writing') || '';
        const writingNotes = button.getAttribute('data-writing-notes') || '';
        const fileName = button.getAttribute('data-file-name') || '';
        const writingId = button.getAttribute('data-writing-id') || '';

        const writingNode = modal.querySelector('[data-modal-writing-input]');
        const notesNode = modal.querySelector('[data-modal-writing-notes-input]');
        const fileNameNode = modal.querySelector('[data-modal-file-name]');
        const audioNode = modal.querySelector('[data-modal-audio]');
        const writingIdNode = modal.querySelector('[data-modal-writing-id]');
        const actionNode = modal.querySelector('[data-modal-action]');
        const deleteNode = modal.querySelector('[data-modal-delete]');

        if (writingNode) {
          writingNode.value = writing;
        }
        if (notesNode) {
          notesNode.value = writingNotes;
        }
        if (fileNameNode) {
          fileNameNode.textContent = fileName || '未登録';
        }
        if (writingIdNode) {
          writingIdNode.value = writingId;
        }
        if (actionNode) {
          actionNode.value = 'update';
        }
        if (deleteNode) {
          deleteNode.addEventListener('click', (event) => {
            const ok = window.confirm('このwritingデータを削除しますか？');
            if (!ok) {
              event.preventDefault();
              return;
            }
            if (actionNode) {
              actionNode.value = 'delete';
            }
          }, { once: true });
        }
        if (audioNode) {
          audioNode.src = fileName || '';
          audioNode.load();
        }
      }

      if (modalId === 'template-form-modal') {
        const mode = button.getAttribute('data-template-mode') || 'create';
        const templateId = button.getAttribute('data-template-id') || '';
        const templateName = button.getAttribute('data-template-name') || '';
        const templateSubject = button.getAttribute('data-template-subject') || '';
        const templateBody = button.getAttribute('data-template-body') || '';
        const templateNotificationBody = button.getAttribute('data-template-notification-body') || '';
        const defaultNotificationBody = modal.getAttribute('data-template-default-notification-body') || '';

        const titleNode = modal.querySelector('[data-template-modal-title]');
        const actionNode = modal.querySelector('[data-template-form-action]');
        const idNode = modal.querySelector('[data-template-form-id]');
        const nameNode = modal.querySelector('[data-template-form-name]');
        const subjectNode = modal.querySelector('[data-template-form-subject]');
        const bodyNode = modal.querySelector('[data-template-form-body]');
        const notificationNode = modal.querySelector('[data-template-form-notification-body]');
        const submitLabelNode = modal.querySelector('[data-template-submit-label]');
        const deleteNode = modal.querySelector('[data-template-delete]');
        const formNode = modal.querySelector('[data-template-form]');

        const isEdit = mode === 'edit';
        if (titleNode) {
          titleNode.textContent = isEdit ? 'テンプレート編集' : 'テンプレート追加';
        }
        if (actionNode) {
          actionNode.value = isEdit ? 'template_update' : 'template_create';
        }
        if (idNode) {
          idNode.value = isEdit ? templateId : '';
        }
        if (nameNode) {
          nameNode.value = isEdit ? templateName : '';
        }
        if (subjectNode) {
          subjectNode.value = isEdit ? templateSubject : '';
        }
        if (bodyNode) {
          bodyNode.value = isEdit ? templateBody : '';
        }
        if (notificationNode) {
          notificationNode.value = isEdit ? templateNotificationBody : defaultNotificationBody;
        }
        if (submitLabelNode) {
          submitLabelNode.textContent = isEdit ? '更新' : '追加';
        }
        if (deleteNode) {
          deleteNode.hidden = !isEdit;
        }
        if (deleteNode && formNode) {
          deleteNode.onclick = () => {
            const ok = window.confirm('このテンプレートを削除しますか？');
            if (!ok) {
              return;
            }
            if (actionNode) {
              actionNode.value = 'template_delete';
            }
            formNode.submit();
          };
        }
      }
      modal.hidden = false;
    });
  });

  document.querySelectorAll('[data-close-modal]').forEach((button) => {
    button.addEventListener('click', () => {
      closeModal(button.closest('.modal'));
    });
  });

  document.querySelectorAll('.modal').forEach((modal) => {
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        closeModal(modal);
      }
    });
  });
  const dropzone = document.querySelector('[data-dropzone]');
  const audioInput = document.querySelector('[data-audio-input]');
  const fileMeta = document.querySelector('[data-file-meta]');

  if (dropzone && audioInput) {
    const updateFileMeta = () => {
      if (!fileMeta) {
        return;
      }
      if (audioInput.files && audioInput.files.length > 0) {
        fileMeta.textContent = `選択中: ${audioInput.files[0].name}`;
      } else {
        fileMeta.textContent = '未選択';
      }
    };

    ['dragenter', 'dragover'].forEach((eventName) => {
      dropzone.addEventListener(eventName, (event) => {
        event.preventDefault();
        dropzone.classList.add('is-dragover');
      });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
      dropzone.addEventListener(eventName, (event) => {
        event.preventDefault();
        dropzone.classList.remove('is-dragover');
      });
    });

    dropzone.addEventListener('drop', (event) => {
      const files = event.dataTransfer ? event.dataTransfer.files : null;
      if (!files || files.length === 0) {
        return;
      }
      audioInput.files = files;
      updateFileMeta();
    });

    audioInput.addEventListener('change', updateFileMeta);
  }
  const refundGuaranteeCheckboxes = document.querySelectorAll('[data-refund-guarantee-checkbox]');
  if (refundGuaranteeCheckboxes.length > 0) {
    refundGuaranteeCheckboxes.forEach((checkbox) => {
      checkbox.addEventListener('change', async () => {
        const questionKey = checkbox.getAttribute('data-question-key') || '';
        const sheetId = checkbox.getAttribute('data-sheet-id') || '';
        if (!questionKey || !sheetId) {
          checkbox.checked = !checkbox.checked;
          window.alert('保存対象の情報が不足しています。');
          return;
        }

        const nextChecked = checkbox.checked;
        checkbox.disabled = true;

        try {
          const response = await window.fetch('refund_guarantee_toggle.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({
              sheet_id: sheetId,
              question_key: questionKey,
              is_checked: nextChecked ? 1 : 0,
            }),
          });

          const payload = await response.json();
          if (!response.ok || !payload.ok) {
            throw new Error(payload.message || '保存に失敗しました。');
          }
        } catch (error) {
          checkbox.checked = !nextChecked;
          window.alert('返金保証条件の保存に失敗しました。');
        } finally {
          checkbox.disabled = false;
        }
      });
    });
  }
  const emailForm = document.querySelector('[data-email-form]');
  if (emailForm) {
    const templateSelect = emailForm.querySelector('[data-template-select]');
    const mailSubject = emailForm.querySelector('[data-mail-subject]');
    const mailBody = emailForm.querySelector('[data-mail-body]');
    const slideConfirmed = emailForm.querySelector('[data-slide-confirmed]');
    const swipeWrap = emailForm.querySelector('[data-swipe-send]');
    const swipeThumb = emailForm.querySelector('[data-swipe-thumb]');
    const attachmentTrigger = emailForm.querySelector('[data-attachment-trigger]');
    const attachmentInput = emailForm.querySelector('[data-attachment-input]');
    const attachmentMeta = emailForm.querySelector('[data-attachment-meta]');

    if (templateSelect && mailSubject && mailBody) {
      templateSelect.addEventListener('change', () => {
        const selected = templateSelect.options[templateSelect.selectedIndex];
        if (!selected || templateSelect.value === '') {
          return;
        }
        mailSubject.value = selected.getAttribute('data-template-subject') || '';
        mailBody.value = selected.getAttribute('data-template-body') || '';
      });
    }

    if (attachmentTrigger && attachmentInput && attachmentMeta) {
      const updateAttachmentMeta = () => {
        const files = attachmentInput.files;
        if (!files || files.length === 0) {
          attachmentMeta.textContent = '未選択';
          return;
        }
        const names = Array.from(files).map((file) => file.name);
        attachmentMeta.textContent = names.join(', ');
      };

      attachmentTrigger.addEventListener('click', () => {
        attachmentInput.click();
      });
      attachmentInput.addEventListener('change', updateAttachmentMeta);
    }
    if (swipeWrap && swipeThumb && slideConfirmed) {
      const track = swipeWrap.querySelector('.swipe-send-track');
      let isDragging = false;
      let startX = 0;
      let startLeft = 4;
      let maxLeft = 0;

      const resetSwipe = () => {
        swipeThumb.style.left = '4px';
        swipeWrap.classList.remove('is-complete');
        slideConfirmed.value = '0';
      };

      const onMove = (clientX) => {
        if (!isDragging || !track) {
          return;
        }
        const nextLeft = Math.min(Math.max(4, startLeft + (clientX - startX)), maxLeft);
        swipeThumb.style.left = `${nextLeft}px`;
      };

      const onEnd = () => {
        if (!isDragging) {
          return;
        }
        isDragging = false;
        const currentLeft = parseFloat(swipeThumb.style.left || '4');
        const successThreshold = maxLeft - 8;
        if (currentLeft >= successThreshold) {
          swipeThumb.style.left = `${maxLeft}px`;
          swipeWrap.classList.add('is-complete');
          slideConfirmed.value = '1';

          const hiddenAction = document.createElement('input');
          hiddenAction.type = 'hidden';
          hiddenAction.name = 'action';
          hiddenAction.value = 'send_email';
          emailForm.appendChild(hiddenAction);
          emailForm.submit();
          return;
        }
        resetSwipe();
      };

      const startDrag = (clientX) => {
        if (!track) {
          return;
        }
        maxLeft = track.clientWidth - swipeThumb.clientWidth - 4;
        isDragging = true;
        startX = clientX;
        startLeft = parseFloat(swipeThumb.style.left || '4');
        swipeThumb.style.cursor = 'grabbing';
      };

      swipeThumb.addEventListener('mousedown', (event) => {
        event.preventDefault();
        startDrag(event.clientX);
      });

      window.addEventListener('mousemove', (event) => onMove(event.clientX));
      window.addEventListener('mouseup', () => {
        swipeThumb.style.cursor = 'grab';
        onEnd();
      });
    }
  }
})();
