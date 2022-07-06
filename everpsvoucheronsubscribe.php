<?php
/**
 * Project : everpsvoucheronsubscribe
 * @author Team Ever
 * @copyright Team Ever
 * @license   Tous droits réservés / Le droit d'auteur s'applique (All rights reserved / French copyright law applies)
 * @link https://www.team-ever.com
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_.'everpsvoucheronsubscribe/models/EverPsVoucherOnSubscribeClass.php';

class Everpsvoucheronsubscribe extends Module
{
    private $html;
    private $postErrors = array();
    private $postSuccess = array();

    public function __construct()
    {
        $this->name = 'everpsvoucheronsubscribe';
        $this->tab = 'pricing_promotion';
        $this->version = '1.2.2';
        $this->author = 'Team Ever';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Ever PS Voucher on subscribe');
        $this->description = $this->l('Automatically create a voucher on customer subscribe');
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->isSeven = Tools::version_compare(_PS_VERSION_, '1.7', '>=') ? true : false;
    }

    public function install()
    {
        include(dirname(__FILE__).'/sql/install.php');
        Configuration::updateValue('CUSTVOUCHER_MINIMAL', 1);
        Configuration::updateValue('CUSTVOUCHER_TAX', 0);
        Configuration::updateValue('CUSTVOUCHER_ENABLE', 0);

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('actionCustomerAccountAdd') &&
            $this->registerHook('actionObjectCustomerDeleteAfter') &&
            $this->createDefaultValues() &&
            $this->registerHook('actionAdminControllerSetMedia');
    }

    private function createDefaultValues()
    {
        Configuration::updateValue('CUSTVOUCHER_MINIMAL', 1);
        Configuration::updateValue('CUSTVOUCHER_TAX', 0);
        Configuration::updateValue('CUSTVOUCHER_ENABLE', 0);
        Configuration::updateValue('CUSTVOUCHER_AMOUNT', 5);
        Configuration::updateValue('CUSTVOUCHER_PERCENT', 0);

        $voucherPrefix = array();
        foreach (Language::getLanguages(false) as $lang) {
            $voucherPrefix[$lang['id_lang']] = 'WELCOME';
        }
        Configuration::updateValue(
            'CUSTVOUCHER_PREFIX',
            $voucherPrefix,
            true
        );

        $voucherDetails = array();
        foreach (Language::getLanguages(false) as $lang) {
            $voucherDetails[$lang['id_lang']] = $this->l('Welcome voucher');
        }
        Configuration::updateValue(
            'CUSTVOUCHER_DETAILS',
            $voucherDetails,
            true
        );
        return true;
    }

    public function uninstall()
    {
        include(dirname(__FILE__).'/sql/uninstall.php');
        Configuration::deleteByName('CUSTVOUCHER_ZONES_TAX');
        Configuration::deleteByName('CUSTVOUCHER_TAX');
        Configuration::deleteByName('CUSTVOUCHER_ENABLE');
        Configuration::deleteByName('CUSTVOUCHER_MINIMAL');
        Configuration::deleteByName('CUSTVOUCHER_CATEGORY');
        Configuration::deleteByName('CUSTVOUCHER_DETAILS');
        Configuration::deleteByName('CUSTVOUCHER_PREFIX');
        return parent::uninstall();
    }

    public function getContent()
    {
        if (((bool)Tools::isSubmit('submitEverpsvoucheronsubscribeModule')) == true) {
            $this->postValidation();

            if (!count($this->postErrors)) {
                $this->postProcess();
            }
        }

        // Display errors
        if (count($this->postErrors)) {
            foreach ($this->postErrors as $error) {
                $this->html .= $this->displayError($error);
            }
        }

        // Display confirmations
        if (count($this->postSuccess)) {
            foreach ($this->postSuccess as $success) {
                $this->html .= $this->displayConfirmation($success);
            }
        }

        $this->context->smarty->assign(array(
            'image_dir' => $this->_path,
        ));

        $this->html .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/header.tpl');
        if ($this->checkLatestEverModuleVersion($this->name, $this->version)) {
            $this->html .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/upgrade.tpl');
        }
        $this->html .= $this->renderForm();
        $this->html .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/footer.tpl');
        return $this->html;
    }

    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitEverpsvoucheronsubscribeModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    protected function getConfigForm()
    {
        $order_states = OrderState::getOrderStates(
            $this->context->language->id
        );
        $currency = new Currency(
            (int)Configuration::get('PS_CURRENCY_DEFAULT')
        );
        $zones = Zone::getZones(true);
        $selected_cat = json_decode(
            Configuration::get(
                'CUSTVOUCHER_CATEGORY'
            )
        );

        if (!is_array($selected_cat)) {
            $selected_cat = array($selected_cat);
        }

        $tree = array(
            'selected_categories' => $selected_cat,
            'use_search' => true,
            'use_checkbox' => true,
            'id' => 'id_category_tree',
        );

        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'label' => $this->l('Enable/Disable the module'),
                        'desc' => $this->l('Enable or Disable the plugin functionality'),
                        'hint' => $this->l('Module will work only if enabled'),
                        'type' => 'switch',
                        'name' => 'CUSTVOUCHER_ENABLE',
                        'values' => array(
                            array(
                                'value' => 1,
                            ),
                            array(
                                'value' => 0,
                            ),
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Allowed customer groups'),
                        'desc' => $this->l('Only these customer groupes will have vouchers'),
                        'hint' => $this->l('Choose allowed groups, customers must be logged'),
                        'name' => 'CUSTVOUCHER_GROUPS[]',
                        'class' => 'chosen',
                        'identifier' => 'name',
                        'multiple' => true,
                        'options' => array(
                            'query' => Group::getGroups(
                                (int)Context::getContext()->cookie->id_lang,
                                (int)$this->context->shop->id
                            ),
                            'id' => 'id_group',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Coupon code prefix'),
                        'desc' => $this->l('All coupons codes will have this prefix'),
                        'hint' => $this->l('Useful to see each generated coupon code'),
                        'name' => 'CUSTVOUCHER_PREFIX',
                        'lang' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Coupon duration (days number)'),
                        'desc' => $this->l('Days coupon code is available'),
                        'hint' => $this->l('Days number duration'),
                        'name' => 'CUSTVOUCHER_DURATION',
                        'lang' => false,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Voucher details'),
                        'name' => 'CUSTVOUCHER_DETAILS',
                        'lang' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Reduction amount'),
                        'desc' => $this->l('Please type reduction amount value'),
                        'hint' => $this->l('Coupons codes will generate this amount reduction'),
                        'name' => 'CUSTVOUCHER_AMOUNT',
                        'lang' => false,
                    ),
                    array(
                        'type' => 'switch',
                        'is_bool' => true,
                        'label' => $this->l('Discount percent or amount'),
                        'desc' => $this->l('Set not for amount'),
                        'hint' => $this->l('Set yes for percent'),
                        'name' => 'CUSTVOUCHER_PERCENT',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        )
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Minimum amount'),
                        'desc' => $this->l('So that the coupon can be used'),
                        'desc' => $this->l('Set 1 as minimal amount value for default'),
                        'name' => 'CUSTVOUCHER_MINIMAL',
                        'prefix' => $currency->sign,
                        'class' => 'fixed-width-sm',
                    ),
                    array(
                        'type' => 'switch',
                        'is_bool' => true,
                        'label' => $this->l('Apply taxes on the voucher'),
                        'desc' => $this->l('Else all vouchers will be without taxes'),
                        'hint' => $this->l('If yes, please choose specific zones for taxes'),
                        'name' => 'CUSTVOUCHER_TAX',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        )
                    ),
                    array(
                        'type' => 'switch',
                        'is_bool' => true,
                        'label' => $this->l('Disallow voucher use on promotions ?'),
                        'desc' => $this->l('Setting to no will allow voucher use on products with reduction'),
                        'label' => $this->l('Else even products with promotions will be allowed for voucher use'),
                        'name' => 'CUSTVOUCHER_PROMO',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Allow taxes on specific zones'),
                        'hint' => $this->l('These zones will have taxes applied on vouchers'),
                        'desc' => $this->l('Leave empty for no use'),
                        'name' => 'CUSTVOUCHER_ZONES_TAX[]',
                        'class' => 'chosen',
                        'identifier' => 'name',
                        'multiple' => true,
                        'options' => array(
                            'query' => $zones,
                            'id' => 'id_zone',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'categories',
                        'name' => 'CUSTVOUCHER_CATEGORY',
                        'label' => $this->l('Allowed categories'),
                        'hint' => $this->l('Choose allowed categories'),
                        'required' => false,
                        'tree' => $tree,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getConfigFormValues()
    {
        $voucherDetails = array();

        foreach (Language::getLanguages(false) as $lang) {
            $voucherDetails[$lang['id_lang']] = (Tools::getValue(
                'CUSTVOUCHER_DETAILS_'
                .$lang['id_lang']
            ))
            ? Tools::getValue(
                'CUSTVOUCHER_DETAILS_'.$lang['id_lang']
            ) : '';
        }

        return array(
            'CUSTVOUCHER_GROUPS[]' => Tools::getValue(
                'CUSTVOUCHER_GROUPS',
                json_decode(
                    Configuration::get(
                        'CUSTVOUCHER_GROUPS',
                        (int)$this->context->language->id
                    )
                )
            ),
            'CUSTVOUCHER_MINIMAL' => Configuration::get('CUSTVOUCHER_MINIMAL'),
            'CUSTVOUCHER_AMOUNT' => Configuration::get('CUSTVOUCHER_AMOUNT'),
            'CUSTVOUCHER_PERCENT' => Configuration::get('CUSTVOUCHER_PERCENT'),
            'CUSTVOUCHER_TAX' => Configuration::get('CUSTVOUCHER_TAX'),
            'CUSTVOUCHER_PROMO' => Configuration::get('CUSTVOUCHER_PROMO'),
            'CUSTVOUCHER_ENABLE' => Configuration::get('CUSTVOUCHER_ENABLE'),
            'CUSTVOUCHER_ZONES_TAX[]' => json_decode(Configuration::get('CUSTVOUCHER_ZONES_TAX')),
            'CUSTVOUCHER_DETAILS' => (!empty($voucherDetails[(int)Configuration::get('PS_LANG_DEFAULT')]))
            ? $voucherDetails : Configuration::getInt('CUSTVOUCHER_DETAILS'),
            'CUSTVOUCHER_PREFIX' => (!empty($voucherPrefix[(int)Configuration::get('PS_LANG_DEFAULT')]))
            ? $voucherPrefix : Configuration::getInt('CUSTVOUCHER_PREFIX'),
            'CUSTVOUCHER_CATEGORY' => Tools::getValue(
                'CUSTVOUCHER_CATEGORY',
                json_decode(
                    Configuration::get(
                        'CUSTVOUCHER_CATEGORY'
                    )
                )
            ),
            'CUSTVOUCHER_DURATION' => Configuration::get('CUSTVOUCHER_DURATION'),
        );
        return $fields_values;
    }

    public function postValidation()
    {
        if (((bool)Tools::isSubmit('submitEverpsvoucheronsubscribeModule')) == true) {
            if (!Tools::getValue('CUSTVOUCHER_GROUPS')
                || !Validate::isArrayWithIds(Tools::getValue('CUSTVOUCHER_GROUPS'))
            ) {
                $this->postErrors[] = $this->l('Error: allowed groups is not valid');
            }
            if (Tools::getValue('CUSTVOUCHER_CATEGORY')
                && !Validate::isArrayWithIds(Tools::getValue('CUSTVOUCHER_CATEGORY'))
            ) {
                $this->postErrors[] = $this->l('Error: allowed categories is not valid');
            }
            if (Tools::getValue('CUSTVOUCHER_PERCENT')
                && !Validate::isBool(Tools::getValue('CUSTVOUCHER_PERCENT'))
            ) {
                $this->postErrors[] = $this->l('Error: amount or percent is not valid');
            }
            if (!Tools::getValue('CUSTVOUCHER_DURATION')
                && !Validate::isUnsignedInt(Tools::getValue('CUSTVOUCHER_DURATION'))
            ) {
                $this->postErrors[] = $this->l('Error: coupon duration days is not valid');
            }
        }
    }

    protected function postProcess()
    {
        $id_lang_default = (int)Configuration::get('PS_LANG_DEFAULT');
        $voucherDetails = array();
        foreach (Language::getLanguages(false) as $lang) {
            $voucherDetails[$lang['id_lang']] = (Tools::getValue(
                'CUSTVOUCHER_DETAILS_'
                .$lang['id_lang']
            ))
            ? Tools::getValue(
                'CUSTVOUCHER_DETAILS_'.$lang['id_lang']
            ) : '';
        }
        Configuration::updateValue(
            'CUSTVOUCHER_DETAILS',
            $voucherDetails,
            true
        );
        $voucherPrefix = array();
        foreach (Language::getLanguages(false) as $lang) {
            $voucherPrefix[$lang['id_lang']] = (Tools::getValue(
                'CUSTVOUCHER_PREFIX_'
                .$lang['id_lang']
            ))
            ? Tools::getValue(
                'CUSTVOUCHER_PREFIX_'.$lang['id_lang']
            ) : '';
        }
        Configuration::updateValue(
            'CUSTVOUCHER_GROUPS',
            json_encode(Tools::getValue('CUSTVOUCHER_GROUPS')),
            true
        );
        Configuration::updateValue(
            'CUSTVOUCHER_PREFIX',
            $voucherPrefix,
            true
        );
        Configuration::updateValue(
            'CUSTVOUCHER_CATEGORY',
            json_encode(Tools::getValue('CUSTVOUCHER_CATEGORY')),
            true
        );
        Configuration::updateValue(
            'CUSTVOUCHER_ZONES_TAX',
            json_encode(Tools::getValue('CUSTVOUCHER_ZONES_TAX')),
            true
        );
        Configuration::updateValue(
            'CUSTVOUCHER_MINIMAL',
            Tools::getValue('CUSTVOUCHER_MINIMAL')
        );
        Configuration::updateValue(
            'CUSTVOUCHER_AMOUNT',
            Tools::getValue('CUSTVOUCHER_AMOUNT')
        );
        Configuration::updateValue(
            'CUSTVOUCHER_PERCENT',
            Tools::getValue('CUSTVOUCHER_PERCENT')
        );
        Configuration::updateValue(
            'CUSTVOUCHER_TAX',
            Tools::getValue('CUSTVOUCHER_TAX')
        );
        Configuration::updateValue(
            'CUSTVOUCHER_PROMO',
            Tools::getValue('CUSTVOUCHER_PROMO')
        );
        Configuration::updateValue(
            'CUSTVOUCHER_ENABLE',
            Tools::getValue('CUSTVOUCHER_ENABLE')
        );
        Configuration::updateValue(
            'CUSTVOUCHER_DURATION',
            Tools::getValue('CUSTVOUCHER_DURATION')
        );
    }

    public function hookActionAdminControllerSetMedia()
    {
        $this->context->controller->addCss($this->_path.'views/css/ever.css');
    }

    public function hookActionCustomerAccountAdd($params)
    {
      if ((int)Configuration::get('CUSTVOUCHER_ENABLE') == 1) {
        $customer = new Customer(
            (int)$params['newCustomer']->id
        );
        $exists = EverPsVoucherOnSubscribeClass::getByCustomer(
            $customer->email
        );
        if (!(bool)$exists) {
            return $this->createFirstVoucher($customer);
        }
      }
    }

    public function createFirstVoucher($customer)
    {
        $duration = (int)Configuration::get('CUSTVOUCHER_DURATION');
        $description = Configuration::getInt('CUSTVOUCHER_DETAILS');
        $prefixx = Configuration::getInt('CUSTVOUCHER_PREFIX');
        $prefix = $prefixx[(int)$this->context->language->id];
        $allowedTaxZones = json_decode(Configuration::get('CUSTVOUCHER_ZONES_TAX'));
        $customer = new Customer((int)$customer->id);
        $customerCountry = $customer->getCurrentCountry((int)$customer->id);
        $country = new Country((int)$customerCountry);
        // Check if is allowed
        $customerGroups = Customer::getGroupsStatic((int)$customer->id);
        $allowed_groups = $this->getAllowedGroups();
        if (!array_intersect($allowed_groups, $customerGroups)
            || empty($allowed_groups)
        ) {
            return;
        }
        /* Generate a voucher code */
        $voucher_code = null;
        do
            $voucher_code = $prefix.''.rand(1000, 100000);
        while (CartRule::cartRuleExists($voucher_code));

        // Voucher creation and affectation to the customer
        $cart_rule = new CartRule();
        $cart_rule->id_customer = (int)$customer->id;
        $cart_rule->date_from = date('Y-m-d H:i:s', strtotime($customer->date_add.' -1 year'));
        $cart_rule->date_to = date('Y-m-d H:i:s', strtotime($customer->date_add.' +'.(int)$duration.' days'));
        $cart_rule->quantity = 1;
        $cart_rule->quantity_per_user = 1;
        $cart_rule->partial_use = 0;
        $cart_rule->code = $voucher_code;
        $cart_rule->cart_rule_restriction = (bool)Configuration::get('CUSTVOUCHER_PROMO');
        $cart_rule->reduction_exclude_special = (bool)Configuration::get('CUSTVOUCHER_PROMO');
        $cart_rule->description = $description[(int)$this->context->language->id];
        $cart_rule->minimum_amount = (float)Configuration::get('CUSTVOUCHER_MINIMAL');
        if ((int)Configuration::get('CUSTVOUCHER_PERCENT')) {
            $cart_rule->reduction_percent = Configuration::get('CUSTVOUCHER_AMOUNT');
        } else {
            $cart_rule->reduction_amount = Configuration::get('CUSTVOUCHER_AMOUNT');
        }
        $cart_rule->highlight = 1;
        if (Configuration::get('CUSTVOUCHER_TAX')
            && in_array((int)$country->id_zone, $allowedTaxZones)
        ) {
            $cart_rule->reduction_tax = 1;
        }
        $cart_rule->active = 1;
        $categories = json_decode(Configuration::get('CUSTVOUCHER_CATEGORY'));
        $languages = Language::getLanguages(true);

        foreach ($languages as $language) {
            $cart_rule->name[(int)$language['id_lang']] = $voucher_code;
        }

        $contains_categories = is_array($categories) && count($categories);
        if ($contains_categories) {
            $cart_rule->product_restriction = 1;
        }
        $cart_rule->add();

        //Restrict cartRules with categories
        if ($contains_categories) {
            //Creating rule group
            $id_cart_rule = (int)$cart_rule->id;
            $sql = "INSERT INTO "._DB_PREFIX_."cart_rule_product_rule_group (id_cart_rule, quantity) VALUES ('$id_cart_rule', 1)";
            Db::getInstance()->execute($sql);
            $id_group = (int)Db::getInstance()->Insert_ID();

            //Creating product rule
            $sql = "INSERT INTO "._DB_PREFIX_."cart_rule_product_rule (id_product_rule_group, type) VALUES ('$id_group', 'categories')";
            Db::getInstance()->execute($sql);
            $id_product_rule = (int)Db::getInstance()->Insert_ID();

            //Creating restrictions
            $values = array();
            foreach ($categories as $category) {
                $category = (int)$category;
                $values[] = "('$id_product_rule', '$category')";
            }
            $values = implode(',', $values);
            $sql = "INSERT INTO "._DB_PREFIX_."cart_rule_product_rule_value (id_product_rule, id_item) VALUES $values";
            Db::getInstance()->execute($sql);
        }
        
        $currency = new Currency(
            (int)Configuration::get('PS_CURRENCY_DEFAULT')
        );

        if ((int)Configuration::get('CUSTVOUCHER_PERCENT')) {
            $reduction = Configuration::get('CUSTVOUCHER_AMOUNT').'%';
        } else {
            $reduction = Configuration::get('CUSTVOUCHER_AMOUNT').''.$currency->sign;
        }

        $mini_amount = (float)Configuration::get('CUSTVOUCHER_MINIMAL');
        $date_to = strftime('%d-%m-%Y',strtotime($cart_rule->date_to));

        Mail::Send(
            (int)(Configuration::get('PS_LANG_DEFAULT')), // defaut language id
            'everpsvoucheronsubscribe', // email template file to be use
            $this->l('Voucher'), // email subject
            array(
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{voucher_num}' => $voucher_code, // email content
                '{voucher_amount}' => $reduction,
                '{voucher_date}' => $date_to,
                '{mini_amount}' => $mini_amount.''.$currency->sign
            ),
            $customer->email, // receiver email
            null, //receiver name
            Configuration::get('PS_SHOP_EMAIL'), //from email address
            Configuration::get('PS_SHOP_NAME'),  //from name
            null,
            null,
            dirname(__FILE__).'/mails/'
        );

        // Save first voucher
        $subscribe_voucher = new EverPsVoucherOnSubscribeClass();
        $subscribe_voucher->id_customer = (int)$customer->id;
        $subscribe_voucher->email = (string)$customer->email;
        $subscribe_voucher->voucher_code = (string)$voucher_code;
        return $subscribe_voucher->save();
    }

    private function getAllowedGroups()
    {
        $groupShop = Shop::getGroupFromShop((int)$this->context->shop->id);
        $allowed_groups = json_decode(
            Configuration::get(
                'CUSTVOUCHER_GROUPS'
            )
        );
        if (!is_array($allowed_groups)) {
            $allowed_groups = array($allowed_groups);
        }
        return $allowed_groups;
    }

    public function checkLatestEverModuleVersion($module, $version)
    {
        $upgrade_link = 'https://upgrade.team-ever.com/upgrade.php?module='
        .$module
        .'&version='
        .$version;
        $handle = curl_init($upgrade_link);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_exec($handle);
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($httpCode != 200) {
            curl_close($handle);
            return false;
        }
        curl_close($handle);
        $module_version = Tools::file_get_contents(
            $upgrade_link
        );
        if ($module_version && $module_version > $version) {
            return true;
        }
        return false;
    }
}
