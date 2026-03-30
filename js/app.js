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
  if (importSheetButton) {
    importSheetButton.addEventListener('click', async () => {
      importSheetButton.disabled = true;
      try {
        const response = await window.fetch('import_sheet_to_db.php', {
          method: 'GET',
          cache: 'no-store',
        });
        if (!response.ok) {
          throw new Error(`status=${response.status}`);
        }
        window.location.reload();
      } catch (error) {
        window.alert('DB取り込みに失敗しました。時間をおいて再度お試しください。');
        importSheetButton.disabled = false;
      }
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
