const checkpointFrames = new Set();
function mountCheckpointPrint() {
  document.querySelectorAll('[data-checkpoint-print]').forEach((button) => {
    if (button.dataset.printMounted) return;
    button.dataset.printMounted = '1';
    let frame = null;
    const label = button.textContent;
    const status = button.closest('section').querySelector('[data-checkpoint-print-status]');
    const reset = () => {
      button.disabled = false;
      button.textContent = label;
    };

    button.addEventListener('click', () => {
      if (button.disabled) return;
      frame?.remove();
      frame = document.createElement('iframe');
      checkpointFrames.add(frame);
      const currentFrame = frame;
      currentFrame.title = 'Kartu checkpoint untuk dicetak';
      currentFrame.setAttribute('aria-hidden', 'true');
      currentFrame.tabIndex = -1;
      Object.assign(currentFrame.style, {
        position: 'fixed',
        width: '210mm',
        height: '148mm',
        left: '0',
        top: '0',
        opacity: '0',
        pointerEvents: 'none',
        zIndex: '-1',
        border: '0',
      });
      button.disabled = true;
      button.textContent = 'Menyiapkan cetakan…';
      status.textContent = 'Memuat kartu A5 landscape…';

      currentFrame.addEventListener(
        'load',
        () => {
          if (currentFrame.isConnected === false) return;
          try {
            if (!currentFrame.contentDocument.querySelector('.checkpoint-card')) {
              throw new Error('missing-card');
            }
            currentFrame.contentWindow.addEventListener(
              'afterprint',
              () => {
                currentFrame.remove();
                if (frame === currentFrame) frame = null;
                reset();
              },
              { once: true }
            );
            status.textContent = '';
            currentFrame.contentWindow.focus();
            currentFrame.contentWindow.print();
            reset();
          } catch {
            currentFrame.remove();
            if (frame === currentFrame) frame = null;
            reset();
            status.textContent =
              'Cetakan tidak dapat dimuat. Muat ulang halaman dan pastikan sesi login masih aktif.';
          }
        },
        { once: true }
      );
      currentFrame.src = button.dataset.printUrl;
      document.body.append(currentFrame);
    });
  });
}
mountCheckpointPrint();
document.addEventListener('mcu:exam-mounted', mountCheckpointPrint);
document.addEventListener('mcu:exam-unmount', () => {
  checkpointFrames.forEach((frame) => frame.remove());
  checkpointFrames.clear();
});
