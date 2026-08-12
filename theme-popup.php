<?php // Partial: theme switcher (pakai komponen theme-popup dari template) ?>
<div class="theme-popup" style="position:fixed; top:16px; right:16px; z-index:1000;">
  <input type="checkbox" id="theme-popup-checkbox" class="theme-popup__checkbox">
  <label class="theme-popup__button" for="theme-popup-checkbox" aria-label="Pilih tema">
    <span class="theme-popup__icons" id="theme-popup-icon"></span>
    <span class="theme-popup__label" id="theme-popup-label">Light</span>
    <svg class="theme-popup__chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
  </label>
  <div class="theme-popup__list-container">
    <ul class="theme-popup__list">
      <?php
        $themes = [
            'light'            => 'Light',
            'dark'             => 'Dark',
            'theme-sakura'     => 'Sakura',
            'theme-bamboo'     => 'Bamboo',
            'theme-cyberpunk'  => 'Cyberpunk',
            'theme-retrolight' => 'Retro 8-bit',
            'theme-mingyu'     => 'Mingyu',
            'theme-ocean'      => 'Ocean',
        ];
        foreach ($themes as $value => $name):
      ?>
        <li>
          <label for="opt-<?= $value ?>">
            <input type="radio" name="theme-radio" id="opt-<?= $value ?>" value="<?= $value ?>" onchange="setTheme(this.value)">
            <span class="theme-popup__icons"><svg class="theme-popup__check" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></span>
            <span><?= $name ?></span>
          </label>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
