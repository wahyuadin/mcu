let disposePatientPhoto = () => {};
function mountPatientPhoto() {
  disposePatientPhoto();
  const form = document.querySelector('[data-patient-photo]');

  if (form) {
    const lifecycle = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const options = lifecycle ? { signal: lifecycle.signal } : undefined;
    const dialog = form.closest('[data-photo-modal]');
    const input = form.querySelector('[data-photo-file]');
    const preview = form.querySelector('[data-photo-preview]');
    const placeholder = form.querySelector('[data-photo-placeholder]');
    const message = form.querySelector('[data-photo-message]');
    const details = form.querySelector('[data-photo-details]');
    const save = form.querySelector('[data-photo-save]');
    const video = form.querySelector('[data-camera]');
    const panel = form.querySelector('[data-camera-panel]');
    const capture = form.querySelector('[data-camera-capture]');
    const start = form.querySelector('[data-camera-start]');
    const drop = form.querySelector('[data-photo-drop]');
    const savedPreview = preview.getAttribute('src');
    let stream = null;
    let facing = 'user';
    let cameraVersion = 0;
    let selectionVersion = 0;
    let selectedFile = null;
    let previewUrl = null;
    let submitted = false;
    let identitySubmitting = false;
    const saveLabel = save.textContent;

    const inform = (text, error = false) => {
      message.textContent = text;
      message.classList.toggle('text-red-700', error);
      message.classList.toggle('text-slate-500', !error);
    };
    const stopCamera = () => {
      cameraVersion += 1;
      stream?.getTracks().forEach((track) => track.stop());
      stream = null;
      video.srcObject = null;
      panel.hidden = true;
      start.hidden = false;
      capture.disabled = true;
    };
    disposePatientPhoto = () => {
      lifecycle?.abort();
      selectionVersion += 1;
      stopCamera();
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      if (dialog?.open) dialog.close();
      document.body.classList.remove('overflow-hidden');
    };
    const clearSelection = () => {
      selectedFile = null;
      input.value = '';
      save.disabled = true;
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = null;
      preview.hidden = !savedPreview;
      placeholder.hidden = Boolean(savedPreview);
      if (savedPreview) preview.src = savedPreview;
      else preview.removeAttribute('src');
      details.textContent = savedPreview
        ? 'Foto sebelumnya tetap tersimpan.'
        : 'Foto belum tersimpan.';
      form.dispatchEvent?.(new Event('mcu:photo-reset', { bubbles: true }));
    };
    if (dialog) {
      let isClosing = false;
      const identityForm = dialog.querySelector?.('[data-patient-identity]');
      identityForm?.addEventListener('submit', (event) => {
        if (identitySubmitting || submitted) {
          event.preventDefault();
          return;
        }
        identitySubmitting = true;
        stopCamera();
      });
      const openModal = () => {
        if (dialog.open) return;
        dialog.showModal();
        document.body.classList.add('overflow-hidden');
        if (typeof dialog.animate === 'function') {
          dialog.animate([{ opacity: 0 }, { opacity: 1 }], {
            duration: window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ? 125 : 200,
            easing: 'cubic-bezier(0.23, 1, 0.32, 1)',
          });
        }
      };
      const closeModal = () => {
        if (!dialog.open || isClosing || submitted || identitySubmitting) return;
        stopCamera();
        if (typeof dialog.animate !== 'function') {
          dialog.close();
          return;
        }
        isClosing = true;
        const anim = dialog.animate([{ opacity: 1 }, { opacity: 0 }], {
          duration: window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ? 125 : 200,
          easing: 'cubic-bezier(0.23, 1, 0.32, 1)',
          fill: 'both',
        });
        anim.onfinish = () => {
          isClosing = false;
          dialog.close();
          anim.cancel?.();
        };
      };
      const openBtn = document.querySelector('[data-photo-modal-open]');
      if (openBtn) openBtn.addEventListener('click', openModal);
      dialog.querySelectorAll('[data-photo-modal-close]').forEach((button) => {
        button.addEventListener('click', (e) => {
          e?.preventDefault?.();
          closeModal();
        });
      });
      dialog.addEventListener('cancel', (e) => {
        e?.preventDefault?.();
        closeModal();
      });
      dialog.addEventListener('close', () => {
        selectionVersion += 1;
        stopCamera();
        clearSelection();
        submitted = false;
        identitySubmitting = false;
        save.textContent = saveLabel;
        inform('Belum ada foto baru dipilih.');
        document.body.classList.remove('overflow-hidden');
        openBtn?.focus?.();
      });
      if (dialog.hasAttribute('data-photo-modal-errors')) openModal();
    }
    const choosePhoto = async (file) => {
      const version = ++selectionVersion;
      if (!file) return;
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        clearSelection();
        inform('Pilih gambar JPG, PNG, atau WebP. SVG, GIF, dan HEIC tidak didukung.', true);
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        clearSelection();
        inform('Ukuran gambar maksimal 5 MB. Pilih file yang lebih kecil.', true);
        return;
      }
      save.disabled = true;
      const url = URL.createObjectURL(file);
      const image = new Image();
      try {
        await new Promise((resolve, reject) => {
          image.onload = resolve;
          image.onerror = reject;
          image.src = url;
        });
        if (version !== selectionVersion) {
          URL.revokeObjectURL(url);
          return;
        }
        if (image.naturalWidth * image.naturalHeight > 20000000) throw new Error('dimensions');
        selectedFile = file;
        form.dispatchEvent?.(new Event('change', { bubbles: true }));
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = url;
        preview.src = url;
        preview.hidden = false;
        placeholder.hidden = true;
        details.textContent = `${file.name} · ${(file.size / 1024).toFixed(0)} KB · ${image.naturalWidth} × ${image.naturalHeight} px`;
        save.disabled = false;
        stopCamera();
        inform('Preview siap. Klik Simpan foto untuk membuka pemeriksaan.');
      } catch (error) {
        URL.revokeObjectURL(url);
        if (version !== selectionVersion) return;
        clearSelection();
        inform(
          error?.message === 'dimensions'
            ? 'Gambar maksimal 20 megapiksel. Gunakan foto yang lebih kecil.'
            : 'Gambar tidak dapat dibaca. Coba JPG, PNG, atau WebP lain.',
          true
        );
      }
    };
    const openCamera = async () => {
      stopCamera();
      const version = cameraVersion;
      if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        inform(
          'Kamera memerlukan HTTPS atau localhost dan browser yang mendukung kamera. Gunakan upload/paste.',
          true
        );
        return;
      }
      start.disabled = true;
      inform('Meminta izin kamera…');
      try {
        const media = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: facing }, width: { ideal: 1280 }, height: { ideal: 960 } },
          audio: false,
        });
        if (version !== cameraVersion) {
          media.getTracks().forEach((track) => track.stop());
          return;
        }
        stream = media;
        video.srcObject = media;
        panel.hidden = false;
        start.hidden = true;
        await video.play();
        if (version !== cameraVersion) return;
        capture.disabled = false;
        inform('Posisikan wajah dengan jelas, lalu klik Ambil foto.');
      } catch (error) {
        if (version !== cameraVersion) return;
        stopCamera();
        inform(
          error.name === 'NotAllowedError'
            ? 'Izin kamera ditolak. Izinkan akses di browser atau gunakan upload.'
            : 'Kamera tidak tersedia atau sedang digunakan. Coba lagi atau upload foto.',
          true
        );
      } finally {
        start.disabled = false;
      }
    };
    start.addEventListener('click', openCamera);
    form.querySelector('[data-camera-retake]').addEventListener('click', openCamera);
    form.querySelector('[data-camera-stop]').addEventListener('click', stopCamera);
    form.querySelector('[data-camera-switch]').addEventListener('click', () => {
      facing = facing === 'user' ? 'environment' : 'user';
      openCamera();
    });
    capture.addEventListener('click', () => {
      if (!video.videoWidth || !video.videoHeight) {
        inform('Kamera belum siap. Tunggu sebentar.', true);
        return;
      }
      capture.disabled = true;
      const version = cameraVersion;
      const canvas = document.createElement('canvas');
      const scale = Math.min(1, 1200 / Math.max(video.videoWidth, video.videoHeight));
      canvas.width = Math.round(video.videoWidth * scale);
      canvas.height = Math.round(video.videoHeight * scale);
      canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
      canvas.toBlob(
        (blob) => {
          if (version !== cameraVersion) return;
          if (!blob) {
            capture.disabled = false;
            inform('Foto gagal diambil. Coba lagi.', true);
            return;
          }
          choosePhoto(new File([blob], `kamera-${Date.now()}.webp`, { type: blob.type }));
        },
        'image/webp',
        0.8
      );
    });
    input.addEventListener('change', () => choosePhoto(input.files[0]));
    form.querySelector('[data-photo-change]').addEventListener('click', () => input.click());
    drop.addEventListener('click', () => input.click());
    drop.addEventListener('keydown', (event) => {
      if (['Enter', ' '].includes(event.key)) {
        event.preventDefault();
        input.click();
      }
    });
    ['dragenter', 'dragover'].forEach((type) =>
      drop.addEventListener(type, (event) => {
        event.preventDefault();
        drop.classList.add('border-emerald-700');
      })
    );
    drop.addEventListener('dragleave', () => drop.classList.remove('border-emerald-700'));
    drop.addEventListener('drop', (event) => {
      event.preventDefault();
      drop.classList.remove('border-emerald-700');
      choosePhoto(event.dataTransfer.files[0]);
    });
    document.addEventListener(
      'paste',
      (event) => {
        if (dialog && !dialog.open) return;
        const item = [...(event.clipboardData?.items ?? [])].find(
          (entry) => entry.kind === 'file' && entry.type.startsWith('image/')
        );
        if (item) {
          event.preventDefault();
          choosePhoto(item.getAsFile());
        }
      },
      options
    );
    form.addEventListener('formdata', (event) => {
      if (selectedFile) event.formData.set('foto_pasien', selectedFile, selectedFile.name);
    });
    form.addEventListener('submit', (event) => {
      if (identitySubmitting || submitted) {
        event.preventDefault();
        return;
      }
      if (!selectedFile || save.disabled) {
        event.preventDefault();
        inform('Pilih atau ambil foto terlebih dahulu.', true);
        return;
      }
      submitted = true;
      stopCamera();
      save.disabled = true;
      save.textContent = 'Mengompres dan menyimpan foto…';
    });
    window.addEventListener(
      'beforeunload',
      (event) => {
        if (selectedFile && !submitted) {
          event.preventDefault();
          event.returnValue = '';
        }
      },
      options
    );
    window.addEventListener('pagehide', stopCamera, options);
    window.addEventListener(
      'pageshow',
      (event) => {
        if (!event.persisted) return;
        submitted = false;
        identitySubmitting = false;
        save.disabled = !selectedFile;
        save.textContent = saveLabel;
      },
      options
    );
    document.addEventListener(
      'visibilitychange',
      () => {
        if (document.hidden) stopCamera();
      },
      options
    );
  }
}
mountPatientPhoto();
document.addEventListener('mcu:exam-unmount', () => disposePatientPhoto());
document.addEventListener('mcu:exam-mounted', mountPatientPhoto);
