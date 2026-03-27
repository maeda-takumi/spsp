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

        const writingNode = modal.querySelector('[data-modal-writing]');
        const notesNode = modal.querySelector('[data-modal-writing-notes]');
        const fileNameNode = modal.querySelector('[data-modal-file-name]');
        const audioNode = modal.querySelector('[data-modal-audio]');

        if (writingNode) {
          writingNode.textContent = writing;
        }
        if (notesNode) {
          notesNode.textContent = writingNotes;
        }
        if (fileNameNode) {
          fileNameNode.textContent = fileName;
        }
        if (audioNode) {
          audioNode.src = fileName;
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
})();
