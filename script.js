(() => {
  const rail = document.querySelector('.rail');
  const trigger = document.getElementById('sticky-trigger');
  const desktopQuery = window.matchMedia('(min-width: 960px)');

  if (!rail || !trigger) return;

  let isSticky = false;
  let rafId = null;
  let enabled = false;

  const setSticky = (next) => {
    if (next === isSticky) return;
    isSticky = next;
    rail.classList.toggle('rail--sticky', next);
  };

  const evaluate = () => {
    const top = trigger.getBoundingClientRect().top;
    if (isSticky) {
      if (top >= -40) {
        setSticky(false);
      }
    } else if (top <= -120) {
      setSticky(true);
    }
  };

  const onScroll = () => {
    if (rafId) return;
    rafId = window.requestAnimationFrame(() => {
      rafId = null;
      evaluate();
    });
  };

  const enable = () => {
    if (enabled) return;
    enabled = true;
    setSticky(false);
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    evaluate();
  };

  const disable = () => {
    if (!enabled) return;
    enabled = false;
    window.removeEventListener('scroll', onScroll);
    window.removeEventListener('resize', onScroll);
    setSticky(false);
  };

  const handleMediaChange = (e) => {
    if (e.matches) {
      enable();
    } else {
      disable();
    }
  };

  desktopQuery.addEventListener('change', handleMediaChange);
  handleMediaChange(desktopQuery);
})();
