// App main JavaScript file
function setColorTheme() {
    const theme = localStorage.theme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    if (theme === 'dark') {
        document.documentElement.classList.add('dark', 'sl-theme-dark');
        document.documentElement.classList.remove('sl-theme-light');
    } else {
        document.documentElement.classList.remove('dark', 'sl-theme-dark');
        document.documentElement.classList.add('sl-theme-light');
    }
}

window.darkMode = () => {
    localStorage.theme = 'dark';
    setColorTheme();
};

window.lightMode = () => {
    localStorage.theme = 'light';
    setColorTheme();
};

window.toggleTheme = () => {
    const current = localStorage.theme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    if (current === 'dark') {
        window.lightMode();
    } else {
        window.darkMode();
    }
};

setColorTheme();
