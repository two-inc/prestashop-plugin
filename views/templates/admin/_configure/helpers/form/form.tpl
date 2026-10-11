{*
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 *}
 
{extends file="helpers/form/form.tpl"}
{* A two_full_width input (the "Tax codes for 0% lines" section, TWO-26153) spans the whole panel: its label sits above
   its content instead of in the label column, which HelperForm sizes differently on 1.7 (col-lg-3) and 8+ (col-lg-4). *}
{block name="label"}
    {if empty($input.two_full_width)}
        {$smarty.block.parent}
    {/if}
{/block}
{block name="field"}
    {if !empty($input.two_full_width)}
        <div class="col-lg-12">
            {if isset($input.label)}
                <label class="control-label">{$input.label}</label>
            {/if}
            {if isset($input.html_content)}
                {$input.html_content}
            {/if}
        </div>
    {else if $input.type == 'file'}
        <div class="col-lg-8">
            <div class="form-group">
                <div class="col-lg-6">
                    <input id="{$input.name}" type="file" name="{$input.name}" class="hide" />
                    <div class="dummyfile input-group">
                        <span class="input-group-addon"><i class="icon-file"></i></span>
                        <input id="{$input.name}-name" type="text" class="disabled" name="filename" readonly />
                        <span class="input-group-btn">
                            <button id="{$input.name}-selectbutton" type="button" name="submitAddAttachments" class="btn btn-default">
                                <i class="icon-folder-open"></i> {l s='Choose a file' mod='twopayment'}
                            </button>
                        </span>
                    </div>
                </div>

            </div>
            <div class="form-group">
                {if isset($fields_value[$input.name]) && $fields_value[$input.name] != ''}
                    <div id="{$input.name}-images-thumbnails" class="col-lg-12">
                        <img src="{$uri}views/img/{$fields_value[$input.name]}" class="img-thumbnail" style="height: 70px;"/>
                        <a class="btn btn-default" href="{$current}&{$identifier}={$form_id|intval}&token={$token}&deleteLogo=1">
                            <i class="icon-trash"></i> {l s='Delete' mod='twopayment'}
                        </a>
                    </div>
                {/if}
            </div>
            <script>

                $(document).ready(function () {
                    $('#{$input.name}-selectbutton').click(function (e) {
                        $('#{$input.name}').trigger('click');
                    });
                    $('#{$input.name}').change(function (e) {
                        var val = $(this).val();
                        var file = val.split(/[\\/]/);
                        $('#{$input.name}-name').val(file[file.length - 1]);
                    });
                });
            </script>

            {if isset($input.desc) && !empty($input.desc)}
                <p class="help-block">
                    {$input.desc}
                </p>
            {/if}
        </div>
    {else if $input.type == 'password'}
        <div class="col-lg-8">
            <input type="password"
                   id="{if isset($input.id)}{$input.id}{else}{$input.name}{/if}"
                   name="{$input.name}"
                   class="{if isset($input.class)}{$input.class}{/if}"
                   value="{$fields_value[$input.name]}"
                   {if isset($input.autocomplete) && !$input.autocomplete}autocomplete="off"{/if}
                   {if isset($input.required) && $input.required } required="required" {/if} />

            {if isset($input.desc) && !empty($input.desc)}
                <p class="help-block">
                    {$input.desc}
                </p>
            {/if}
        </div>

    {else}
        {$smarty.block.parent}
    {/if}
{/block}
{* The Order management form's end field (TWO-26153): after the last fieldset and its buttons, so it is the post's last
   value and its absence means PHP cut the post at max_input_vars. *}
{block name="other_fieldsets"}
    {$smarty.block.parent}
    {if isset($two_form_end_field) && $two_form_end_field}
        <input type="hidden" name="{$two_form_end_field|escape:'html':'UTF-8'}" value="1" />
    {/if}
{/block}
