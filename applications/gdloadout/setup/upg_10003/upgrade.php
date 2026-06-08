<?php

namespace IPS\gdloadout\setup\upg_10003;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		$tpl = [
			'template_set_id' => 1,
			'template_app' => 'gdloadout',
			'template_location' => 'front',
			'template_group' => 'loadouts',
			'template_name' => 'builder',
			'template_data' => '$initData',
			'template_content' => <<<'TEMPLATE_EOT'
<div class="gdlo-builder" id="gdloBuilder" data-init='{$initData|raw}'>
    <div class="gdlo-builder-header">
        <h1 class="gdlo-builder-title">{lang="gdloadout_builder_title"}</h1>
    </div>
    <div class="gdlo-builder-body">
        <div class="gdlo-builder-meta">
            <div class="gdlo-builder-field">
                <label for="gdloName">{lang="gdloadout_name"}</label>
                <input type="text" id="gdloName" class="gdlo-input" maxlength="100" placeholder="{lang="gdloadout_name_placeholder"}" />
            </div>
            <div class="gdlo-builder-field">
                <label for="gdloDesc">{lang="gdloadout_description"}</label>
                <textarea id="gdloDesc" class="gdlo-input" rows="2" maxlength="500" placeholder="{lang="gdloadout_desc_placeholder"}"></textarea>
            </div>
            <div class="gdlo-builder-row">
                <div class="gdlo-builder-field">
                    <label for="gdloUseCase">{lang="gdloadout_use_case"}</label>
                    <select id="gdloUseCase" class="gdlo-input">
                        <option value="">{lang="gdloadout_select_use_case"}</option>
                        <option value="Home Defense">Home Defense</option>
                        <option value="Concealed Carry">Concealed Carry</option>
                        <option value="Competition">Competition</option>
                        <option value="Hunting">Hunting</option>
                        <option value="Long Range">Long Range</option>
                        <option value="Plinking">Plinking</option>
                        <option value="Duty">Duty</option>
                        <option value="Bug Out">Bug Out</option>
                        <option value="Custom">Custom</option>
                    </select>
                </div>
                <div class="gdlo-builder-field">
                    <label for="gdloVisibility">{lang="gdloadout_visibility"}</label>
                    <select id="gdloVisibility" class="gdlo-input">
                        <option value="public">{lang="gdloadout_public"}</option>
                        <option value="unlisted">{lang="gdloadout_unlisted"}</option>
                        <option value="private">{lang="gdloadout_private"}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="gdlo-builder-search">
            <label>{lang="gdloadout_search_products"}</label>
            <div class="gdlo-builder-search-box">
                <input type="text" id="gdloSearch" class="gdlo-input" placeholder="{lang="gdloadout_search_placeholder"}" />
                <button type="button" id="gdloSearchBtn" class="gdlo-btn gdlo-btn--primary gdlo-btn--sm"><i class="fa-solid fa-search"></i></button>
            </div>
            <div id="gdloSearchResults" class="gdlo-builder-results"></div>
        </div>

        <div class="gdlo-builder-slots" id="gdloSlots">
            <h3>{lang="gdloadout_your_items"}</h3>
            <div id="gdloSlotList" class="gdlo-builder-slot-list"></div>
        </div>
    </div>

    <div class="gdlo-builder-footer">
        <button type="button" id="gdloSaveBtn" class="gdlo-btn gdlo-btn--primary"><i class="fa-solid fa-floppy-disk"></i> {lang="gdloadout_save"}</button>
        <button type="button" id="gdloDeleteBtn" class="gdlo-btn gdlo-btn--danger" style="display:none"><i class="fa-solid fa-trash"></i> {lang="gdloadout_delete"}</button>
    </div>
</div>
TEMPLATE_EOT,
			'template_updated' => time(),
			'template_version' => '1.0.3',
		];

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', $tpl );
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
