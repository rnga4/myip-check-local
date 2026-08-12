/* Theme switcher untuk PL-Komatsu UI Template */
var THEMES = ['light', 'dark', 'theme-sakura', 'theme-bamboo', 'theme-cyberpunk', 'theme-retrolight', 'theme-mingyu', 'theme-ocean'];
var THEME_NAMES = {
  'light': 'Light', 'dark': 'Dark', 'theme-sakura': 'Sakura', 'theme-bamboo': 'Bamboo',
  'theme-cyberpunk': 'Cyberpunk', 'theme-retrolight': 'Retro 8-bit', 'theme-mingyu': 'Mingyu', 'theme-ocean': 'Ocean'
};

function setTheme(theme, save) {
  if (THEMES.indexOf(theme) === -1) theme = 'light';
  document.body.classList.add('theme-transitioning');
  THEMES.forEach(function (t) {
    if (t !== 'light') document.body.classList.remove(t);
  });
  if (theme !== 'light') document.body.classList.add(theme);
  if (save !== false) localStorage.setItem('plkomatsu-theme', theme);

  document.querySelectorAll('input[name="theme-radio"]').forEach(function (r) {
    r.checked = (r.value === theme);
  });
  var label = document.getElementById('theme-popup-label');
  if (label) label.textContent = THEME_NAMES[theme];
  var cb = document.getElementById('theme-popup-checkbox');
  if (cb) cb.checked = false;

  setTimeout(function () { document.body.classList.remove('theme-transitioning'); }, 400);
}

document.addEventListener('DOMContentLoaded', function () {
  setTheme(localStorage.getItem('plkomatsu-theme') || 'light', false);
});
