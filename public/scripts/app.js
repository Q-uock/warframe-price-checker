(() => {
    const quickTools = document.getElementById('floatingQuickTools');
    const button = document.getElementById('scrollTopButton');
    if (!quickTools || !button) {
        return;
    }

    const syncVisibility = () => {
        quickTools.classList.toggle('is-visible', window.scrollY > 260);
    };

    button.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    window.addEventListener('scroll', syncVisibility, { passive: true });
    syncVisibility();
})();
