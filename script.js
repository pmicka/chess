(() => {
  const railEl = document.querySelector('.rail');
  const triggerEl = document.getElementById('sticky-trigger');
  const desktopQuery = window.matchMedia('(min-width: 960px)');

  if (!railEl || !triggerEl) return;

  let isSticky = false;
  let rafId = null;
  let enabled = false;

  const setSticky = (next) => {
    if (next === isSticky) return;
    isSticky = next;
    railEl.classList.toggle('rail--sticky', next);
  };

  const evaluate = () => {
    rafId = null;
    const t = Math.round(triggerEl.getBoundingClientRect().top);

    if (!isSticky && t <= -140) {
      setSticky(true);
      return;
    }

    if (isSticky && t >= -20) {
      setSticky(false);
    }
  };

  const onScroll = () => {
    if (rafId !== null) return;
    rafId = window.requestAnimationFrame(evaluate);
  };

  const enable = () => {
    if (enabled) return;
    enabled = true;
    setSticky(false);
    window.addEventListener('scroll', onScroll, { passive: true });
    evaluate();
  };

  const disable = () => {
    if (!enabled) return;
    enabled = false;
    window.removeEventListener('scroll', onScroll);
    if (rafId !== null) {
      window.cancelAnimationFrame(rafId);
      rafId = null;
    }
    setSticky(false);
  };

  const handleMediaChange = (event) => {
    if (event.matches) {
      enable();
    } else {
      disable();
    }
  };

  desktopQuery.addEventListener('change', handleMediaChange);
  handleMediaChange(desktopQuery);
})();
