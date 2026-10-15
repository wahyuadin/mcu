const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
const cardsIn = (list) => [...list.querySelectorAll('[data-hrd-edit-index]')];
const reducedMotion = (win) =>
  win.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
const runningAnimations = new WeakMap();

export function captureDashboardRects(list) {
  return new Map(
    cardsIn(list).map((card) => [card.dataset.dashboardKey, card.getBoundingClientRect?.()])
  );
}

export function animateDashboardLayout(list, before, { skip, animate = true } = {}) {
  const win = list.ownerDocument.defaultView ?? window;
  const easing =
    win.getComputedStyle?.(list)?.getPropertyValue?.('--ease-out')?.trim() ||
    'cubic-bezier(0.23, 1, 0.32, 1)';
  cardsIn(list).forEach((card) => {
    const previous = before.get(card.dataset.dashboardKey);
    runningAnimations.get(card)?.cancel();
    if (!previous || card === skip || !animate || reducedMotion(win) || !card.animate) return;
    const next = card.getBoundingClientRect();
    const x = previous.left - next.left;
    const y = previous.top - next.top;
    if (Math.abs(x) + Math.abs(y) < 1) return;
    const animation = card.animate(
      [{ transform: `translate(${x}px, ${y}px)` }, { transform: 'translate(0, 0)' }],
      { duration: 200, easing }
    );
    runningAnimations.set(card, animation);
    animation.finished?.catch(() => {});
  });
}

export function setupDashboardGestures({
  list,
  canStart,
  getWidget,
  onSelect,
  onStart,
  onReorder,
  onResize,
  onCommit,
  onCancel,
}) {
  const doc = list.ownerDocument;
  const win = doc.defaultView ?? window;
  const raf = (callback) =>
    win.requestAnimationFrame ? win.requestAnimationFrame(callback) : win.setTimeout(callback, 16);
  const cancelRaf = (id) =>
    win.cancelAnimationFrame ? win.cancelAnimationFrame(id) : win.clearTimeout(id);
  let gesture;
  let frame;
  let settle;
  let suppressClick = false;
  const indexOf = (card) => cardsIn(list).indexOf(card);
  const easing = () =>
    win.getComputedStyle?.(list)?.getPropertyValue?.('--ease-out')?.trim() ||
    'cubic-bezier(0.23, 1, 0.32, 1)';
  const updateIndices = () =>
    cardsIn(list).forEach((card, index) => {
      card.dataset.hrdEditIndex = index;
    });
  const reorder = (from, to, animate = true) => {
    const cards = cardsIn(list);
    if (from === to || to < 0 || to >= cards.length) return;
    const before = captureDashboardRects(list);
    list.insertBefore(cards[from], from < to ? cards[to].nextElementSibling : cards[to]);
    updateIndices();
    onReorder(from, to);
    animateDashboardLayout(list, before, { skip: gesture?.card, animate });
  };
  const resize = (width, height, animate = true) => {
    const card = gesture.card;
    const index = indexOf(card);
    const widget = getWidget(index);
    if (widget.width === width && widget.height === height) return;
    const before = captureDashboardRects(list);
    onResize(index, width, height);
    card.dataset.width = width;
    card.dataset.height = height;
    animateDashboardLayout(list, before, { skip: card, animate });
  };
  const releaseCapture = (pointerId) => {
    if (pointerId != null && list.hasPointerCapture?.(pointerId))
      list.releasePointerCapture(pointerId);
  };
  const cleanStyles = (card) => {
    delete card.dataset.dragPlaceholder;
    delete card.dataset.resizing;
    delete card.dataset.keyboardMoving;
    card.style.width = '';
    card.style.height = '';
  };
  const finish = (cancel = false) => {
    if (!gesture) return;
    cancelRaf(frame);
    frame = undefined;
    const current = gesture;
    gesture = null;
    releaseCapture(current.pointerId);
    delete list.dataset.gesture;
    if (!current.started) return;
    suppressClick = true;
    win.setTimeout(() => {
      suppressClick = false;
    }, 0);
    const before = captureDashboardRects(list);
    if (cancel) {
      current.order.forEach((card) =>
        list.insertBefore(card, list.querySelector('[data-dashboard-add-card]'))
      );
      updateIndices();
      onCancel();
    }
    const free = current.card.getBoundingClientRect();
    if (current.kind !== 'drag' || current.keyboard) cleanStyles(current.card);
    const target = current.card.getBoundingClientRect();
    animateDashboardLayout(list, before, { skip: current.card, animate: !current.keyboard });
    if (!cancel) onCommit();
    const ghost = current.ghost;
    if (ghost && !current.keyboard && !reducedMotion(win) && ghost.animate) {
      const animation = ghost.animate(
        [
          { transform: ghost.style.transform },
          {
            transform: `translate(${target.left - current.bounds.left}px, ${target.top - current.bounds.top}px)`,
          },
        ],
        { duration: 180, easing: easing(), fill: 'forwards' }
      );
      let cleared = false;
      const clear = () => {
        if (cleared) return;
        cleared = true;
        animation.cancel();
        ghost.remove();
        cleanStyles(current.card);
        if (settle === clear) settle = undefined;
      };
      settle = clear;
      animation.finished.then(clear, clear);
    } else {
      ghost?.remove();
      cleanStyles(current.card);
      if (
        current.kind === 'resize' &&
        !cancel &&
        !current.keyboard &&
        !reducedMotion(win) &&
        current.card.animate
      ) {
        const outline = doc.createElement('div');
        outline.className = 'hrd-resize-outline';
        outline.setAttribute('aria-hidden', 'true');
        Object.assign(outline.style, {
          left: `${target.left}px`,
          top: `${target.top}px`,
          width: `${target.width}px`,
          height: `${target.height}px`,
        });
        list.append(outline);
        const animation = outline.animate(
          [
            {
              transform: `scale(${free.width / target.width}, ${free.height / target.height})`,
              opacity: 1,
            },
            { transform: 'scale(1, 1)', opacity: 0 },
          ],
          { duration: 180, easing: easing() }
        );
        let cleared = false;
        const clear = () => {
          if (cleared) return;
          cleared = true;
          animation.cancel();
          outline.remove();
          if (settle === clear) settle = undefined;
        };
        settle = clear;
        animation.finished.then(clear, clear);
      }
    }
    (current.focusTarget ?? current.card).focus({ preventScroll: true });
  };
  const begin = () => {
    const current = gesture;
    current.started = true;
    onStart(current.kind);
    current.bounds = current.card.getBoundingClientRect();
    list.dataset.gesture = current.kind;
    current.card.focus({ preventScroll: true });
    list.setPointerCapture?.(current.pointerId);
    if (current.kind === 'drag') {
      runningAnimations.get(current.card)?.cancel();
      const ghost = current.card.cloneNode(true);
      delete ghost.dataset.hrdEditIndex;
      delete ghost.dataset.dashboardKey;
      ghost.classList.add('hrd-drag-ghost');
      ghost.setAttribute('aria-hidden', 'true');
      ghost.inert = true;
      Object.assign(ghost.style, {
        left: `${current.bounds.left}px`,
        top: `${current.bounds.top}px`,
        width: `${current.bounds.width}px`,
        height: `${current.bounds.height}px`,
      });
      list.append(ghost);
      current.ghost = ghost;
      current.card.dataset.dragPlaceholder = 'true';
    } else {
      Object.assign(current.card.style, {
        width: `${current.bounds.width}px`,
        height: `${current.bounds.height}px`,
      });
      current.card.dataset.resizing = 'true';
    }
  };
  const tick = () => {
    frame = undefined;
    if (!gesture?.started || gesture.keyboard) return;
    const current = gesture;
    const dx = current.x - current.startX;
    const dy = current.y - current.startY;
    if (current.kind === 'drag') {
      current.ghost.style.transform = `translate(${dx}px, ${dy}px)`;
      const hit = doc.elementFromPoint?.(current.x, current.y)?.closest('[data-hrd-edit-index]');
      if (hit && list.contains(hit) && hit !== current.card)
        reorder(indexOf(current.card), indexOf(hit));
    } else {
      const horizontal = current.edge !== 's';
      const vertical = current.edge !== 'e';
      const baseHeight = Math.max(140, current.bounds.height - (current.height - 1) * 190);
      const freeHeight = vertical
        ? clamp(current.bounds.height + dy, baseHeight, baseHeight + 190)
        : current.bounds.height;
      const width =
        horizontal && current.columns > 1
          ? clamp(current.visibleWidth + Math.round(dx / current.pitch), 1, current.maxSpan)
          : current.width;
      const height = vertical ? clamp(current.height + Math.round(dy / 190), 1, 2) : current.height;
      resize(width, height);
      const availableWidth =
        list.getBoundingClientRect().right - current.card.getBoundingClientRect().left;
      const freeWidth = horizontal
        ? clamp(
            current.bounds.width + dx,
            current.pitch - current.gap,
            Math.min(current.maxWidth, availableWidth)
          )
        : current.bounds.width;
      current.card.style.width = `${freeWidth}px`;
      current.card.style.height = `${freeHeight}px`;
    }
    const viewport = win.innerHeight ?? 0;
    const delta = current.y > viewport - 48 ? 12 : current.y < 48 ? -12 : 0;
    if (viewport && delta && win.scrollBy) {
      const previous = win.scrollY;
      win.scrollBy(0, delta);
      if (win.scrollY !== previous) frame = raf(tick);
    }
  };
  const pointerDown = (event) => {
    if (gesture || !canStart() || event.button !== 0 || event.isPrimary === false) return;
    const card = event.target.closest('[data-hrd-edit-index]');
    if (!card || !list.contains(card)) return;
    const handle = event.target.closest('[data-dashboard-resize]');
    if (
      !handle &&
      event.target.closest('button, a, input, select, textarea, [contenteditable="true"]')
    )
      return;
    if (!handle && event.pointerType === 'touch' && !event.target.closest('.hrd-widget-heading'))
      return;
    settle?.();
    const index = indexOf(card);
    const widget = getWidget(index);
    const bounds = card.getBoundingClientRect();
    const listBounds = list.getBoundingClientRect();
    const computed = win.getComputedStyle(list);
    const columns = Math.max(1, computed.gridTemplateColumns.split(' ').length);
    const gap = parseFloat(computed.columnGap) || 20;
    const pitch = (listBounds.width + gap) / columns;
    gesture = {
      card,
      kind: handle ? 'resize' : 'drag',
      edge: handle?.dataset.dashboardResize,
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      x: event.clientX,
      y: event.clientY,
      bounds,
      width: widget.width,
      height: widget.height,
      columns,
      gap,
      pitch,
      visibleWidth: Math.min(columns, widget.width),
      maxSpan: Math.min(3, columns),
      maxWidth: listBounds.width,
      order: cardsIn(list),
      started: false,
    };
    onSelect(index);
    if (handle) {
      event.preventDefault();
      begin();
    }
  };
  const pointerMove = (event) => {
    if (!gesture || event.pointerId !== gesture.pointerId || gesture.keyboard) return;
    gesture.x = event.clientX;
    gesture.y = event.clientY;
    if (!gesture.started && Math.hypot(gesture.x - gesture.startX, gesture.y - gesture.startY) >= 6)
      begin();
    if (gesture.started) {
      event.preventDefault();
      if (frame === undefined) frame = raf(tick);
    }
  };
  const pointerUp = (event) => {
    if (!gesture || gesture.keyboard || gesture.pointerId !== event.pointerId) return;
    if (gesture.started) {
      cancelRaf(frame);
      tick();
    }
    finish(event.type === 'pointercancel');
  };
  const keyDown = (event) => {
    if (event.key === 'Escape' && gesture?.started) {
      event.preventDefault();
      finish(true);
      return;
    }
    if (event.target.closest('button, input, select, textarea, a')) return;
    const card = event.target.closest('[data-hrd-edit-index]');
    if (!card || !canStart()) return;
    if (gesture && (!gesture.keyboard || gesture.card !== card)) return;
    const handle = event.target.closest('[data-dashboard-resize]');
    if (handle && !gesture && event.key.startsWith('Arrow')) {
      event.preventDefault();
      const index = indexOf(card);
      const widget = getWidget(index);
      onSelect(index);
      onStart('resize');
      gesture = {
        card,
        kind: 'resize',
        started: true,
        keyboard: true,
        focusTarget: handle,
        order: cardsIn(list),
      };
      resize(
        clamp(
          widget.width + (event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0),
          1,
          3
        ),
        clamp(
          widget.height + (event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : 0),
          1,
          2
        ),
        false
      );
      finish();
      return;
    }
    if (event.target !== card) return;
    if (event.key === ' ' || event.key === 'Enter') {
      event.preventDefault();
      if (gesture?.keyboard) finish();
      else if (event.key === ' ') {
        const index = indexOf(card);
        onSelect(index);
        onStart('drag');
        gesture = { card, kind: 'drag', started: true, keyboard: true, order: cardsIn(list) };
        card.dataset.keyboardMoving = 'true';
      }
    } else if (gesture?.keyboard && event.key.startsWith('Arrow')) {
      event.preventDefault();
      const from = indexOf(card);
      reorder(from, from + (['ArrowUp', 'ArrowLeft'].includes(event.key) ? -1 : 1), false);
    }
  };
  list.addEventListener('pointerdown', pointerDown);
  doc.addEventListener('pointermove', pointerMove, { passive: false });
  doc.addEventListener('pointerup', pointerUp);
  doc.addEventListener('pointercancel', pointerUp);
  doc.addEventListener('keydown', keyDown);
  list.addEventListener('lostpointercapture', (event) => {
    if (gesture?.pointerId === event.pointerId) finish(true);
  });
  list.addEventListener('dragstart', (event) => event.preventDefault());
  list.addEventListener(
    'click',
    (event) => {
      if (suppressClick) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    },
    true
  );
  doc.addEventListener('visibilitychange', () => {
    if (doc.hidden) finish(true);
  });
  win.addEventListener('pagehide', () => {
    finish(true);
    settle?.();
  });
  win.addEventListener('blur', () => finish(true));
  return {
    active: () => Boolean(gesture?.started),
    cancel: () => {
      finish(true);
      settle?.();
    },
  };
}
