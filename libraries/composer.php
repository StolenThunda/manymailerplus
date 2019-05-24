<?php
/**
 * This source file is part of the open source project
 * ExpressionEngine (https://expressionengine.com).
 *
 * @see      https://expressionengine.com/
 *
 * @copyright Copyright (c) 2003-2018, EllisLab, Inc. (https://ellislab.com)
 * @license   https://expressionengine.com/license Licensed under Apache License, Version 2.0
 */
use EllisLab\ExpressionEngine\Library\CP\Table;
use EllisLab\ExpressionEngine\Model\Email\EmailCache;

// use ManyMailer\Models\EmailCachePlus as EmailCache;

/**
 * Copy of Communicate Controller.
 */
class Composer
{
    private $attachments = array();
    private $csv_lookup = array();
    private $csv_email_column = '{{email}}';
    private $debug = false;
    private $init_service = null;
    private $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );

    /**
     * Constructor.
     */
    public function __construct($service = null)
    {
        $CI = ee();

        if (!ee()->cp->allowed_group('can_access_comm')) {
            show_error(lang('unauthorized_access'), 403);
        }
        ee()->config->load('compose_js');

        $internal_js = ee()->config->item('internal_js');
        foreach ($internal_js as $js) {
            ee()->cp->load_package_js($js);
        }
        $external_js = ee()->config->item('external_js');
        foreach ($external_js as $script) {
            ee()->cp->add_to_foot($script);
        }

        if (isset($service['debug'])) {
            $this->debug = $service['debug'];
        }
        if (isset($service['service'])) {
            $this->init_service = 'mandrill'; //$service['service'];
        }
    }

    /**
     * compose.
     *
     * @param obj $email An EmailCache object for use in re-populating the form (see: resend())
     */
    public function compose(EmailCache $email = null)
    {
        $default = array(
            'from' => ee()->session->userdata('email'),
            'from_name' => ee()->session->userdata('screen_name'),
            'recipient' => '',
            'cc' => '',
            'bcc' => '',
            'subject' => '',
            'message' => '',
            'plaintext_alt' => '',
            'mailtype' => ee()->config->item('mail_format'),
            'wordwrap' => ee()->config->item('word_wrap'),
        );

        $vars['mailtype_options'] = array(
            'text' => lang('plain_text'),
            'markdown' => lang('markdown'),
            'html' => lang('html'),
        );

        $member_groups = array();

        if (!is_null($email)) {
            $default['from'] = $email->from_email;
            $default['recipient'] = $email->recipient;
            $default['cc'] = $email->cc;
            $default['bcc'] = $email->bcc;
            $default['subject'] = str_replace('', '(TEMPLATE) ', $email->subject);
            $default['message'] = $email->message;
            $default['plaintext_alt'] = $email->plaintext_alt;
            $default['mailtype'] = $email->mailtype;
            $default['wordwrap'] = $email->wordwrap;
        }
        // Set up member group emailing options
        if (ee()->cp->allowed_group('can_email_member_groups')) {
            $groups = ee('Model')->get('MemberGroup')
                ->filter('site_id', ee()->config->item('site_id'))
                ->all();

            $member_groups = [];
            $disabled_groups = [];
            foreach ($groups as $group) {
                $member_groups[$group->group_id] = $group->group_title;

                if (ee('Model')->get('Member')
                    ->filter('group_id', $group->group_id)
                    ->count() == 0) {
                    $disabled_groups[] = $group->group_id;
                }
            }
        }

        $csvHTML = array(
            form_textarea(
                array(
                    'name' => 'csv_recipient',
                    'id' => 'csv_recipient',
                    'rows' => '10',
                    'class' => 'required',
                )
            ),
            form_button('convert_csv', 'Convert CSV', 'class="btn"'),
        );

        if ($default['mailtype'] != 'html') {
            ee()->javascript->output('$("textarea[name=\'plaintext_alt\']").parents("fieldset").eq(0).hide();');
        }

        $template_view = ee('View')->make(EXT_SHORT_NAME.':email/embed_templates');

        $vars['sections'] = array(
            'sender_info' => array(
                array(
                    'title' => 'from_email',
                    'desc' => 'from_email_desc',
                    'fields' => array(
                        'from' => array(
                            'type' => 'text',
                            'value' => $default['from'],
                        ),
                    ),
                ),
                array(
                    'title' => 'from_name',
                    'desc' => 'from_name_desc',
                    'fields' => array(
                        'from_name' => array(
                            'type' => 'text',
                            'value' => $default['from_name'],
                        ),
                    ),
                ),
            ),
            'recipient_options' => array(
                array(
                    'title' => 'recipient_entry',
                    'fields' => array(
                        'recipient_entry' => array(
                            'type' => 'select',
                            'choices' => array(
                                'file_recipient' => lang('upload'),
                                'csv_recipient' => lang('manual'),
                            ),
                        ),
                    ),
                ),
                array(
                    'title' => 'file_recipient',
                    'desc' => 'file_recipient_desc',
                    'fields' => array(
                        'files' => array(
                            'type' => 'html',
                            'content' => form_input(
                                array(
                                    'id' => 'files',
                                    'name' => 'files[]',
                                    'type' => 'hidden',
                                    'value' => '0',
                                )
                            ),
                        ),
                        'file_recipient' => array(
                            'type' => 'file',
                        'content' => ee('CP/FilePicker')
                                ->make()
                                ->getLink('Choose File')
                                ->withValueTarget('files')
                                ->render(),
                        ),
                        'dump_vars' => array(
                            'type' => 'html',
                            'content' => form_button('btnDump', 'Dump Hidden Values', 'class="btn" onClick="dumpHiddenVals()"'),
                        ),
                        'csv_object' => array(
                            'type' => 'hidden',
                            'value' => '',
                        ),
                        'mailKey' => array(
                            'type' => 'hidden',
                            'value' => '',
                        ),
                    ),
                ),
                array(
                    'title' => 'csv_recipient',
                    'desc' => 'csv_recipient_desc',
                    'fields' => array(
                        'csv_errors' => array(
                            'type' => 'html',
                            'content' => '<span id="csv_errors"></span>',
                        ),
                        'csv_recipient' => array(
                            'type' => 'html',
                            'content' => implode('<br />', $csvHTML),
                        ),
                    ),
                ),
                array(
                    'title' => 'primary_recipients',
                    'desc' => 'primary_recipients_desc',
                    'fields' => array(
                        'recipient' => array(
                            'type' => 'text',
                            'value' => $default['recipient'],
                            'required' => true,
                        ),
                        'csv_reset' => array(
                            'type' => 'html',
                            'content' => form_button('btnReset', 'Reset CSV Data', 'id="reset" class="btn"'),
                        ),
                        'csv_content' => array(
                            'type' => 'html',
                            'content' => '<table class=\'fixed_header\' id=\'csv_content\'></table>',
                        ),
                    ),
                ),
            ),
            'compose_email_detail' => array(
                array(
                    'title' => 'use_templates',
                    'desc' => 'use_templates_desc',
                    'fields' => array(
                        'use_template' => array(
                            'type' => 'html',
                            'content' => form_yes_no_toggle('use_templates', false), //.BR.BR. $template_view->render($this->view_templates()),
                        ),
                        'template_list' => array(
                            'type' => 'html',
                            'content' => $template_view->render($this->view_templates()),
                        ),
                    ),
                ),
                array(
                    'title' => 'template_name',
                    'desc' => '_template_name',
                    'fields' => array(
                        'template_name' => array(
                            'type' => 'html',
                            'content' => form_input(array(
                                'id' => 'template_name',
                                'name' => 'template_name',
                            )),
                        ),
                    ),
                ),
                array(
                    'title' => 'email_subject',
                    'fields' => array(
                        'subject' => array(
                            'type' => 'text',
                            'required' => true,
                            'value' => $default['subject'],
                        ),
                    ),
                ),
                array(
                    'title' => 'message',
                    'fields' => array(
                        'message' => array(
                            'type' => 'html',
                            'content' => ee('View')->make(EXT_SHORT_NAME.':email/body-field')
                                ->render($default + $vars),
                        ),
                    ),
                ),
                array(
                    'title' => 'plaintext_body',
                    'desc' => 'plaintext_alt',
                    'fields' => array(
                        'plaintext_alt' => array(
                            'type' => 'textarea',
                            'value' => $default['plaintext_alt'],
                            'required' => true,
                        ),
                    ),
                ),
                array(
                    'title' => 'attachment',
                    'desc' => 'attachment_desc',
                    'fields' => array(
                        'attachment' => array(
                            'type' => 'file',
                        ),
                    ),
                ),
            ),

        'other_recipient_options' => array(
            array(
                'title' => 'cc_recipients',
                'desc' => 'cc_recipients_desc',
                'fields' => array(
                    'cc' => array(
                        'type' => 'text',
                        'value' => $default['cc'],
                    ),
                ),
            ),
            array(
                'title' => 'bcc_recipients',
                'desc' => 'bcc_recipients_desc',
                'fields' => array(
                    'bcc' => array(
                        'type' => 'text',
                        'value' => $default['bcc'],
                    ),
                ),
            ),
            ),
        );

        if (ee()->cp->allowed_group('can_email_member_groups')) {
            $vars['sections']['other_recipient_options'][] = array(
                'title' => 'add_member_groups',
                'desc' => 'add_member_groups_desc',
                'fields' => array(
                    'member_groups' => array(
                        'type' => 'checkbox',
                        'choices' => $member_groups,
                        'disabled_choices' => $disabled_groups,
                    ),
                ),
            );
        }
        $vars['cp_page_title'] = lang('compose_heading');
        // $vars['categories'] = array_keys($this->sidebar_options);
        $vars['base_url'] = ee('CP/URL', EXT_SETTINGS_PATH.'/email/send');
        $vars['save_btn_text'] = lang('compose_send_email');
        $vars['save_btn_text_working'] = lang('compose_sending_email');
        ee()->cp->add_js_script(array(
            'file' => array('cp/form_group'),
          ));
        ee()->cp->add_to_foot(link_tag(array(
            'href' => 'http://cdn.datatables.net/1.10.19/css/jquery.dataTables.min.css',
            'rel' => 'stylesheet',
            'type' => 'text/css',
        )));
        ee()->dbg->console_message($vars, __METHOD__);

        return $vars;
    }

    /**
     * compose.
     *
     * @param obj $email An EmailCache object for use in re-populating the form (see: resend())
     */
    public function compose2(EmailCache $email = null)
    {
        $default = array(
            'from' => ee()->session->userdata('email'),
            'from_name' => ee()->session->userdata('screen_name'),
            'recipient' => '',
            'cc' => '',
            'bcc' => '',
            'subject' => '',
            'message' => '',
            'plaintext_alt' => '',
            'mailtype' => ee()->config->item('mail_format'),
            'wordwrap' => ee()->config->item('word_wrap'),
        );

        $vars['mailtype_options'] = array(
            'text' => lang('plain_text'),
            'markdown' => lang('markdown'),
            'html' => lang('html'),
        );

        $member_groups = array();

        if (!is_null($email)) {
            $default['from'] = $email->from_email;
            $default['recipient'] = $email->recipient;
            $default['cc'] = $email->cc;
            $default['bcc'] = $email->bcc;
            $default['subject'] = str_replace('', '(TEMPLATE) ', $email->subject);
            $default['message'] = $email->message;
            $default['plaintext_alt'] = $email->plaintext_alt;
            $default['mailtype'] = $email->mailtype;
            $default['wordwrap'] = $email->wordwrap;
        }
        // Set up member group emailing options
        if (ee()->cp->allowed_group('can_email_member_groups')) {
            $groups = ee('Model')->get('MemberGroup')
                ->filter('site_id', ee()->config->item('site_id'))
                ->all();

            $member_groups = [];
            $disabled_groups = [];
            foreach ($groups as $group) {
                $member_groups[$group->group_id] = $group->group_title;

                if (ee('Model')->get('Member')
                    ->filter('group_id', $group->group_id)
                    ->count() == 0) {
                    $disabled_groups[] = $group->group_id;
                }
            }
        }

        $csvHTML = array(
            form_textarea(
                array(
                    'name' => 'csv_recipient',
                    'id' => 'csv_recipient',
                    'rows' => '10',
                    'class' => 'required',
                )
            ),
            form_button('convert_csv', 'Convert CSV', 'class="btn"'),
        );

        if ($default['mailtype'] != 'html') {
            // ee()->javascript->output('$("div[data-control=\'plaintext_alt\']").hide();');
            ee()->javascript->output('$("textarea[name=\'plaintext_alt\']").parents("fieldset").eq(0).hide();');
        }

        $form_cls = ' class="form-control"';

        $template_view = ee('View')->make(EXT_SHORT_NAME.':email/embed_templates');
        // $template_view->disable(array('remove', 'data-attribute'));

        $vars['sections'] = array(
            'sender_info' => array(
                'from_email' => '*'.form_input('from_email', $default['from'], 'required=true', $form_cls),
                'from_name' => form_input('from_name', $default['from_name']),
            ),
            'recipient_options' => array(
                'recipient_entry' => form_dropdown('recipient_entry', array(
                    'file_recipient' => lang('upload'),
                    'csv_recipient' => lang('manual'),
                ), 'upload'),
                '' => form_hidden('files[]', 0, 'id="files"'),
                'file_recipient' => form_upload('file_recipient'),
                '' => form_button(
                        'btnDump',
                        'Dump Hidden Values',
                        'class="btn" onClick="dumpHiddenVals()" '),
                '' => form_hidden('csv_object'),
                '' => form_hidden('mailKey'),
                '' => '<span id="csv_errors"></span><hr/>',
                'csv_recipient' => form_textarea(
                    array(
                        'name' => 'csv_recipient',
                        'id' => 'csv_recipient',
                        'rows' => '10',
                    )
                ).BR.form_button('convert_csv', 'Convert CSV', 'class="btn"'), //.BR.BR.form_button(array('id'=>'reset'),'Reset CSV Data','class="btn1" ').BR.BR,
                'primary_recipients' => '*'.form_input(array(
                    'name' => 'recipient',
                    // 'required' => "true"
                ), $default['recipient']).BR.BR.form_button(array('id' => 'reset'), 'Reset CSV Data', 'class="btn1" '),
                'recipient_review' => '<table class=\'fixed_header\' id=\'csv_content\'></table>'.BR.NBS,
            ),
            'compose_email_detail' => array(
                'use_templates' => form_yes_no_toggle('use_templates', false).BR.BR.$template_view->render($this->view_templates()).BR.BR,
                '_template_name' => form_input(array('id' => 'template_name', 'name' => 'template_name')),
                'subject' => '*'.form_input('subject', $default['subject']),
                'message' => ee('View')->make(EXT_SHORT_NAME.':email/body-field')->render($default + $vars),
                'plaintext_alt' => form_textarea('plaintext_alt', $default['plaintext_alt']),
                'attachment' => form_upload('attachment'),
            ),
            'other_recipient_options' => array(
                'cc_recipients' => form_input('cc_recipients', $default['cc']),
                'bcc_recipients' => form_input('bcc_recipients', $default['bcc']),
            ),
        );

        // if (ee()->cp->allowed_group('can_email_member_groups'))
        // {
        // 	$vars['sections']['other_recipient_options'][] = array(
        // 		'add_member_groups' => form_checkbox('add_member_groups', )
        // 		// 'title' => 'add_member_groups',
        // 		// 'desc' => 'add_member_groups_desc',
        // 		// 'fields' => array(
        // 		// 	'member_groups' => array(
        // 		// 		'type' => 'checkbox',
        // 		// 		'choices' => $member_groups,
        // 		// 		'disabled_choices' => $disabled_groups,
        // 		// 	)
        // 		// )
        // 	);
        // }
        $vars['cp_page_title'] = lang('compose_heading');
        // $vars['categories'] = array_keys($this->sidebar_options);
        $vars['base_url'] = ee('CP/URL', EXT_SETTINGS_PATH.'/email/send');
        $vars['save_btn_text'] = lang('compose_send_email');
        $vars['save_btn_text_working'] = lang('compose_sending_email');
        // ee()->cp->add_js_script(array(
        // 	'file' => array('cp/form_group'),
        //   ));
        // ee()->cp->add_to_foot(link_tag(array(
        // 	'href' => 'http://cdn.datatables.net/1.10.19/css/jquery.dataTables.min.css',
        // 	'rel' => 'stylesheet',
        // 	'type' => 'text/css',
        // )));
        ee()->dbg->console_message($vars, __METHOD__);

        return array(
            'body' => ee('View')->make(EXT_SHORT_NAME.':compose_view2')->render($vars),
            'breadcrumb' => array(
                ee('CP/URL')->make(EXT_SETTINGS_PATH)->compile() => EXT_NAME,
                ee('CP/URL')->make(EXT_SETTINGS_PATH.'/email')->compile() => lang('email_title'),
            ),
            'heading' => $vars['cp_page_title'],
        );
    }

    public function edit_template($template_name = '')
    {
        $message = ee()->session->flashdata('result');
        if ($message) {
            $message = explode(':', ee()->session->flashdata('result'));
            ee()->dbg->console_message('Msg: '.implode(':', $message), __METHOD__);
            ee('CP/Alert')->makeInline('result')
                        ->asIssue()
                        ->withTitle(lang('template_'.$message[0]))
                        ->addToBody(end($message))
                        ->canClose()
                        ->now();

            ee('CP/Alert')->makeInline('save_template_req')
                        ->asIssue()
                        ->withTitle(ee()->session->flashdata('save_endpoint'))
                        ->addToBody(ee()->session->flashdata('save_api_data'))
                        ->canClose()
                        ->now();
        }

        if ($template_name != '') {
            $template_name = str_replace('_', ' ', $template_name);
        }
        $default = array(
            'template_name' => '',
            'from_email' => ee()->session->userdata('email'),
            'from_name' => ee()->session->userdata('screen_name'),
            'subject' => '',
            'code' => '',
            'text' => '',
            'publish' => false,
            'created_at' => '',
            'labels' => array(),
        );
        ee()->dbg->console_message('TEMP NAME: '.$template_name, __METHOD__);

        if ($template_name !== '') {
            $template = $this->_get_service_templates('info', $template_name);
            // ee()->dbg->console_message($template, __METHOD__);
            if (isset($template['status'])) {
                ee()->session->set_flashdata('result', $template['status'].':'.$template['message']);
                ee()->functions->redirect(ee('CP/URL', EXT_SETTINGS_PATH.'/email/edit_template'));
            }
            $default['template_name'] = $template['name'];
            $default['from_email'] = $template['from_email'];
            $default['from_name'] = $template['from_name'];
            $default['code'] = $template['code'];
            $default['subject'] = $template['subject'];
            $default['text'] = $template['text'];
            $default['publish'] = isset($template['publish_code']);
            $default['labels'] = $template['labels'];
            $default['created_at'] = $template['created_at'];
        }
        $has_template_name = ($default['template_name'] !== '');
        $vars['sections'] = array(
            array(
                array(
                    'title' => 'template_name',
                    'desc' => 'template_name_desc',
                    'fields' => array(
                        'orig_template_name' => array(
                            'type' => 'hidden',
                            'value' => $default['template_name'],
                        ),
                        'template_name' => array(
                            'type' => 'text',
                            'value' => $default['template_name'],
                            'disabled' => $has_template_name,
                            'required' => !$has_template_name,
                        ),
                    ),
                ),
                array(
                    'title' => ($default['created_at'] === '') ? '' : 'created_at',
                    'fields' => array(
                        'created_at_hidden' => array(
                            'type' => 'hidden',
                            'value' => $default['created_at'],
                        ),
                        'created_at' => array(
                            'type' => ($default['created_at'] === '') ? 'hidden' : 'text',
                            'value' => $default['created_at'],
                            'disabled' => true,
                        ),
                    ),
                ),
            ),
            'template_info' => array(
                array(
                    'title' => 'from_email',
                    'desc' => 'from_email_desc',
                    'fields' => array(
                        'from_email' => array(
                            'type' => 'text',
                            'value' => $default['from_email'],
                        ),
                    ),
                ),
                array(
                    'title' => 'from_name',
                    'desc' => 'from_name_desc',
                    'fields' => array(
                        'from_name' => array(
                            'type' => 'text',
                            'value' => $default['from_name'],
                        ),
                    ),
                ),

                array(
                    'title' => 'subject',
                    'desc' => 'subject_desc',
                    'fields' => array(
                      'subject' => array(
                        'type' => 'text',
                        'value' => $default['subject'],
                        ),
                    ),
                ),
                array(
                    'title' => 'code',
                    'desc' => 'code_desc',
                    'fields' => array(
                        'code' => array(
                            'type' => 'html',
                            'content' => form_textarea(array('name' => 'code', 'rows' => 15), $default['code']),
                            // 'type' => 'textarea',
                            // 'value' => $default['code'],
                        ),
                    ),
                ),
                array(
                    'title' => 'text',
                    'desc' => 'text_desc',
                    'fields' => array(
                      'text' => array(
                        'type' => 'text',
                        'value' => $default['text'],
                        ),
                    ),
                ),
                array(
                    'title' => 'publish',
                    'desc' => 'publish_desc',
                    'fields' => array(
                        'publish' => array(
                        'type' => 'yes_no',
                        'choices' => array(
                            'y' => true,
                            'n' => false,
                        ),
                        'value' => $default['publish'],
                        ),
                    ),
                ),
            ),
        );

        $vars['cp_page_title'] = lang(__FUNCTION__);
        // $vars['categories'] = array_keys($this->sidebar_options);
        $vars['base_url'] = ee('CP/URL', EXT_SETTINGS_PATH.'/email/save_template');
        $vars['save_btn_text'] = lang('save_template');
        $vars['save_btn_text_working'] = lang('saving_template');

        ee()->dbg->console_message($vars, __METHOD__);

        return $vars;
    }

    public function save_template()
    {
        $form_fields = array(
            'created_at_hidden',
            'orig_template_name',
            'template_name',
            'from_email',
            'from_name',
            'subject',
            'code',
            'text',
            'publish',
            // "labels",
        );

        // ee()->dbg->console_message($_POST, __METHOD__);
        foreach ($_POST as $key => $val) {
            // ee()->dbg->console_message("$key : ".ee()->input->post($key),__METHOD__);
            if (in_array($key, $form_fields)) {
                $$key = ee()->input->get_post($key);
                // ee()->dbg->console_message("$key : ".ee()->input	->post($key),__METHOD__);
            }
        }

        if (isset($template_name)) {
            ee()->load->library('form_validation');
            ee()->form_validation->set_rules('template_name', 'lang:template_name', 'required|valid_xss_check');
            if (ee()->form_validation->run() === false) {
                ee()->view->set_message('issue', lang('save_template_error'), lang('save_template_error_desc'));
                echo '<pre>';
                print_r($_POST);
                echo '</pre>';

                return $this->edit_template($template_name);
            }
        }
        $cache_data = array(
            'key' => $this->_get_mandrill_api(),
            'name' => (isset($template_name) ? $template_name : $orig_template_name),
            'from_email' => $from_email,
            'from_name' => $from_name,
            'subject' => $subject,
            'code' => utf8_encode($code),
            'text' => $text,
            'publish' => ($publish == 'y'),
            // "labels" => explode(',', $labels),
        );
        $function = ($created_at_hidden !== '') ? 'update' : 'add';

        $api_endpoint = 'https://mandrillapp.com/api/1.0/templates/'.$function.'.json';
        // ee()->dbg->console_message($api_endpoint . json_encode($cache_data), __METHOD__);
        $result = $this->curl_request($api_endpoint, $this->headers, $cache_data, true);
        if (isset($result['status'])) {
            ee()->view->set_message($result['status'], $result['message'], null, true);
            ee()->session->set_flashdata('result', $result['status'].':'.$result['message']);
        }

        ee()->functions->redirect(ee('CP/URL', EXT_SETTINGS_PATH.'/email/edit_template/'.(isset($template_name) ? $template_name : $orig_template_name)));
    }

    /**
     * Prepopulate form to send to specific member.
     *
     * @param int $id
     *
     * @return void
     */
    public function member($id)
    {
        $member = ee('Model')->get('Member', $id)->first();
        $this->member = $member;

        if (empty($member)) {
            show_404();
        }

        $cache_data = array(
            'recipient' => $member->email,
            'from_email' => ee()->session->userdata('email'),
        );

        // $email = ee('Model')->get(EXT_SHORT_NAME.':', $cache_data);
        $email = ee('Model')->get('EmailCache', $cache_data);
        $email->removeMemberGroups();
        $this->compose($email);
    }

    /**
     * Send Email.
     */
    public function send()
    {
        ee()->load->library('email');

        // Fetch $_POST data
        // We'll turn the $_POST data into variables for simplicity

        $groups = array();

        $form_fields = array(
            'subject',
            'message',
            'plaintext_alt',
            'mailtype',
            'wordwrap',
            'from',
            'attachment',
            'recipient',
            'cc',
            'bcc',
            // 'csv_object',
            // 'mailKey',
            // 'template_name',
        );

        $extras = array();
        $wordwrap = 'n';

        foreach ($_POST as $key => $val) {
            if ($key == 'member_groups') {
                // filter empty inputs, like a hidden no-value input from React
                $groups = array_filter(ee()->input->post($key));
            } elseif (in_array($key, $form_fields)) {
                $$key = ee()->input->post($key);
            // ee()->dbg->console_message($$key, __METHOD__);
                // ee()->dbg->console_message($key . ' (' . gettype($key) . ')', __METHOD__);
            } else {
                $extras[$key] = $val;
            }
        }

        if (isset($extras['mailKey'])) {
            $this->csv_email_column = $extras['mailKey'];
        }
        // create lookup array for easy email lookup
        if (isset($extras['csv_object']) and $extras['csv_object'] !== '' and isset($extras['mailKey'])) {
            $rows = json_decode($extras['csv_object'], true);
            foreach ($rows as $row) {
                $this->csv_lookup[trim($row[$extras['mailKey']])] = $row;
            }
        }

        // ee()->dbg->console_message($this->csv_lookup,__METHOD__);
        //  Verify privileges
        if (count($groups) > 0 && !ee()->cp->allowed_group('can_email_member_groups')) {
            show_error(lang('not_allowed_to_email_member_groups'));
        }

        // Set to allow a check for at least one recipient
        $_POST['total_gl_recipients'] = count($groups);

        ee()->load->library('form_validation');
        // ee()->form_validation->set_rules('subject', 'lang:subject', 'required|valid_xss_check');
        // ee()->form_validation->set_rules('message', 'lang:message', 'required');
        ee()->form_validation->set_rules('from', 'lang:from', 'required|valid_email');
        ee()->form_validation->set_rules('cc', 'lang:cc', 'valid_emails');
        ee()->form_validation->set_rules('bcc', 'lang:bcc', 'valid_emails');
        ee()->form_validation->set_rules('recipient', 'lang:recipient', 'valid_emails|callback__check_for_recipients');
        ee()->form_validation->set_rules('attachment', 'lang:attachment', 'callback__attachment_handler');

        if (ee()->form_validation->run() === false) {
            ee()->view->set_message('issue', lang('compose_error'), lang('compose_error_desc'));

            return $this->compose();
        }

        $name = ee()->session->userdata('screen_name');

        $debug_msg = '';

        switch ($mailtype) {
            case 'text':
                $text_fmt = 'none';
                $plaintext_alt = '';
                break;

            case 'markdown':
                $text_fmt = 'markdown';
                $mailtype = 'html';
                $plaintext_alt = $message;
                break;

            case 'html':
                // If we strip tags and it matches the message, then there was
                // not any HTML in it and we'll format for them.
                if ($message == strip_tags($message)) {
                    $text_fmt = 'xhtml';
                } else {
                    $text_fmt = 'none';
                }
                break;
        }

        $subject = "${subject} (TEMPLATE) ";

        // Assign data for caching
        $cache_data = array(
            'cache_date' => ee()->localize->now,
            'total_sent' => 0,
            'from_name' => $name,
            'from_email' => $from,
            'recipient' => $recipient,
            'cc' => $cc,
            'bcc' => $bcc,
            'recipient_array' => array(),
            'subject' => $subject,
            'message' => $message,
            'mailtype' => $mailtype,
            'wordwrap' => $wordwrap,
            'text_fmt' => $text_fmt,
            'total_sent' => 0,
            'plaintext_alt' => $plaintext_alt,
            'attachments' => $this->attachments,
        );
        ee()->dbg->console_message(array_merge($extras, $cache_data), __METHOD__);
        $email = ee('Model')->make('EmailCache', $cache_data);
        $email->save();

        // Get member group emails
        $member_groups = ee('Model')->get('MemberGroup', $groups)
            ->with('Members')
            ->all();

        $email_addresses = array();
        foreach ($member_groups as $group) {
            foreach ($group->getMembers() as $member) {
                $email_addresses[] = $member->email;
            }
        }

        if (empty($email_addresses) and $recipient == '') {
            show_error(lang('no_email_matching_criteria'));
        }

        /** ----------------------------------------
        /**  Do we have any CCs or BCCs?
        /** ----------------------------------------*/

        //  If so, we'll send those separately first

        $total_sent = 0;

        if ($cc != '' or $bcc != '') {
            $to = ($recipient == '') ? ee()->session->userdata['email'] : $recipient;
            $debug_msg = $this->deliverOneEmail($email, $to, empty($email_addresses));

            $total_sent = $email->total_sent;
        } else {
            // No CC/BCCs? Convert recipients to an array so we can include them in the email sending cycle

            if ($recipient != '') {
                foreach (explode(',', $recipient) as $address) {
                    $address = trim($address);

                    if (!empty($address)) {
                        $email_addresses[] = $address;
                    }
                }
            }
        }

        //  Store email cache
        $email->recipient_array = $email_addresses;
        $email->setMemberGroups(ee('Model')->get('MemberGroup', $groups)->all());
        $email->save();
        $id = $email->cache_id;

        // Is Batch Mode set?

        $batch_mode = bool_config_item('email_batchmode');
        $batch_size = (int) ee()->config->item('email_batch_size');

        if (count($email_addresses) <= $batch_size) {
            $batch_mode = false;
        }

        //** ----------------------------------------
        //  If batch-mode is not set, send emails
        // ----------------------------------------*/

        if ($batch_mode == false) {
            $total_sent = $this->deliverManyEmails($email, $extras);

            $debug_msg = ee()->email->print_debugger(array());

            $this->deleteAttachments($email); // Remove attachments now

            ee()->view->set_message('success', lang('total_emails_sent').' '.$total_sent, $debug_msg, true);
            ee()->functions->redirect(ee('CP/URL', EXT_SETTINGS_PATH.'/email/compose'));
        }

        if ($batch_size === 0) {
            show_error(lang('batch_size_is_zero'));
        }

        /* ----------------------------------------
        **  Start Batch-Mode
        ** ----------------------------------------*/

        // ee()->functions->redirect(ee('CP/URL',EXT_SETTINGS_PATH.'/'.EXT_SHORT_NAME.'/email:compose'));
        ee()->view->set_refresh(ee('CP/URL', EXT_SETTINGS_PATH.'/email/batch/'.$email->cache_id)->compile(), 6, true);

        ee('CP/Alert')->makeStandard('batchmode')
            ->asWarning()
            ->withTitle(lang('batchmode_ready_to_begin'))
            ->addToBody(lang('batchmode_warning'))
            ->defer();
        consol_message($cache_data, __METHOD__, true);
        ee()->functions->redirect(ee('CP/URL', EXT_SETTINGS_PATH.'/email/compose'));
    }

    /**
     * Batch Email Send.
     *
     * Sends email in batch mode
     *
     * @param int $id The cache_id to send
     */
    public function batch($id)
    {
        ee()->load->library('email');

        if (ee()->config->item('email_batchmode') != 'y') {
            show_error(lang('batchmode_disabled'));
        }

        if (!ctype_digit($id)) {
            show_error(lang('problem_with_id'));
        }

        $email = ee('Model')->get(EXT_SHORT_NAME.':', $id)->first();

        if (is_null($email)) {
            show_error(lang('cache_data_missing'));
        }

        $start = $email->total_sent;

        $this->deliverManyEmails($email);

        if ($email->total_sent == count($email->recipient_array)) {
            $debug_msg = ee()->email->print_debugger(array());

            $this->deleteAttachments($email); // Remove attachments now

            ee()->view->set_message('success', lang('total_emails_sent').' '.$email->total_sent, $debug_msg, true);
            ee()->functions->redirect(ee('CP/URL', EXT_SETTINGS_PATH.'/'.EXT_SHORT_NAME.'/email:compose'));
        } else {
            $stats = str_replace('%x', ($start + 1), lang('currently_sending_batch'));
            $stats = str_replace('%y', ($email->total_sent), $stats);

            $message = $stats.BR.BR.lang('emails_remaining').NBS.NBS.(count($email->recipient_array) - $email->total_sent);

            ee()->view->set_refresh(ee('CP/URL', EXT_SETTINGS_PATH.'/'.EXT_SHORT_NAME.'/email:batch/'.$email->cache_id)->compile(), 6, true);

            ee('CP/Alert')->makeStandard('batchmode')
                ->asWarning()
                ->withTitle($message)
                ->addToBody(lang('batchmode_warning'))
                ->defer();

            ee()->functions->redirect(is_valid_uri);
        }
    }

    /**
     * Fetches an email from the cache and presents it to the user for re-sending.
     *
     * @param int $id The cache_id to send
     */
    public function resend($id)
    {
        if (!ctype_digit($id)) {
            show_error(lang('problem_with_id'));
        }

        // $caches = ee('Model')->get(EXT_SHORT_NAME.'EmailCachePlus', $id)
        $caches = ee('Model')->get('EmailCache', $id)
            ->with('MemberGroups')
            ->all();

        $email = $caches[0];

        if (is_null($email)) {
            show_error(lang('cache_data_missing'));
        }

        ee()->dbg->console_message($email->subject, __METHOD__);

        return $this->compose($email);
    }

    /**
     * Sends a single email handling errors.
     *
     * @param obj    $email  An EmailCache object
     * @param string $to     An email address to send to
     * @param bool   $delete Delete email attachments after send?
     *
     * @return string A response messge as a result of sending the email
     */
    private function deliverOneEmail(EmailCache $email, $to, $delete = true)
    {
        $error = false;

        if (!$this->deliverEmail($email, $to, $email->cc, $email->bcc)) {
            $error = true;
        }

        if ($delete) {
            $this->deleteAttachments($email); // Remove attachments now
        }

        $debug_msg = ee()->email->print_debugger(array());

        if ($error == true) {
            $this->_removeMail($email);
        }

        $total_sent = 0;

        foreach (array($to, $email->cc, $email->bcc) as $string) {
            if ($string != '') {
                $total_sent += substr_count($string, ',') + 1;
            }
        }

        // Save cache data
        $email->total_sent = $total_sent;
        $email->save();

        return $debug_msg;
    }

    /**
     * Sends multiple emails handling errors.
     *
     * * @param	obj	$email	An EmailCache object
     *
     * @return int The number of emails sent
     */
    private function deliverManyEmails(EmailCache $email, $extras = null)
    {
        $recipient_array = array_slice($email->recipient_array, $email->total_sent);
        $number_to_send = count($recipient_array);
        $csv_lookup_loaded = (count($this->csv_lookup) > 0);

        if ($number_to_send < 1) {
            return 0;
        }

        if (ee()->config->item('email_batchmode') == 'y') {
            $batch_size = (int) ee()->config->item('email_batch_size');

            if ($number_to_send > $batch_size) {
                $number_to_send = $batch_size;
            }
        }

        $formatted_message = $this->formatMessage($email, true);
        for ($x = 0; $x < $number_to_send; ++$x) {
            $email_address = array_shift($recipient_array);
            ee()->dbg->console_message('lookup loaded: '.$csv_lookup_loaded, __FUNCTION__.'loaded');
            if ($csv_lookup_loaded) {
                $tmp_plaintext = $email->plaintext_alt;
                $record = $this->csv_lookup[$email_address];
                ee()->dbg->console_message($record, __METHOD__);
                // standard 'First Last <email address> format
                if (isset($record['{{first_name}}']) && isset($record['{{last_name}}'])) {
                    $to = "{$record['{{first_name}}']} {$record['{{last_name}}']}  <{$record['{{email}}']}>"; //TODO: https://trello.com/c/1lffhlXm
                } else {
                    $to = $record[$this->csv_email_column];
                }

                $cache_data = array(
                    'cache_date' => ee()->localize->now,
                    'total_sent' => 0,
                    'from_name' => $email->from_name,
                    'from_email' => $email->from_email,
                    'recipient' => $to,
                    'cc' => $email->cc,
                    'bcc' => $email->bcc,
                    'recipient_array' => array(),
                    'subject' => str_replace('(TEMPLATE) ', '', $email->subject),
                    'message' => $formatted_message,
                    'mailtype' => $email->mailtype,
                    'wordwrap' => $email->wordwrap,
                    'text_fmt' => $email->text_fmt,
                    'total_sent' => 0,
                    'plaintext_alt' => $email->message,
                    'attachments' => $this->attachments,
                );

                $singleEmail = ee('Model')->make('EmailCache', $cache_data);
                $singleEmail->save();

                $cache_data['lookup'] = $record;
                $cache_data['html'] = $formatted_message;
                $cache_data['extras'] = (count($extras) > 0) ? $extras : array();
                ee()->dbg->console_message($cache_data, __METHOD__);
                if ($this->email_send($cache_data)) {
                    ++$singleEmail->total_sent;
                    $singleEmail->save();
                } else {
                    $this->_removeMail($email);
                }
            } elseif (!$this->deliverEmail($email, $email_address)) {
                $this->_removeMail($email);
            }
            ++$email->total_sent;
        }
        $email->save();

        return $email->total_sent;
    }

    private function _removeMail(EmailCache $email)
    {
        $email->delete();

        $debug_msg = ee()->email->print_debugger(array());
        $err_msg = lang('compose_error').BR.BR.$debug_msg;
        ee()->dbg->console_message($debug_msg, __METHOD__);
        ee()->logger->developer($err_msg);
        show_error($err_msg);
    }

    /**
     * Delivers an email.
     *
     * @param obj    $email An EmailCache object
     * @param string $to    An email address to send to
     * @param string $cc    An email address to cc
     * @param string $bcc   An email address to bcc
     *
     * @return bool True on success; False on failure
     */
    private function deliverEmail(EmailCache $email, $to, $cc = null, $bcc = null)
    {
        ee()->email->clear(true);
        ee()->email->wordwrap = $email->wordwrap;
        ee()->email->mailtype = $email->mailtype;
        ee()->email->from($email->from_email, $email->from_name);
        ee()->email->to($to);

        if (!is_null($cc)) {
            ee()->email->cc($email->cc);
        }

        if (!is_null($bcc)) {
            ee()->email->bcc($email->bcc);
        }

        ee()->email->subject($this->censorSubject($email));
        ee()->email->message($this->formatMessage($email), $email->plaintext_alt);

        foreach ($email->attachments as $attachment) {
            ee()->email->attach($attachment);
        }
        ee()->dbg->console_message(ee()->email->print_debugger(), __METHOD__);

        return ee()->email->send(false);
    }

    /**
     * Formats the message of an email based on the text format type.
     *
     * @param obj $email An EmailCache object
     *
     * @return string The  message
     */
    private function formatMessage(EmailCache $email, $markdown_only = false)
    {
        $message = $email->message;
        if ($email->text_fmt != 'none' && $email->text_fmt != '') {
            ee()->load->library('typography');
            ee()->typography->initialize(array(
                'bbencode_links' => false,
                'parse_images' => false,
                'parse_smileys' => false,
            ));
            if ($markdown_only) {
                $message = ee()->typography->markdown($email->message, array(
                        'convert_curly' => false,
                ));
            } else {
                $message = ee()->typography->parse_type($email->message, array(
                    'text_format' => $email->text_fmt,
                    'convert_curly' => false,
                    'html_format' => 'all',
                    'auto_links' => 'n',
                    'allow_img_url' => 'y',
                ));
            }
        }

        return $message;
    }

    /**
     * Censors the subject of an email if necessary.
     *
     * @param obj $email An EmailCache object
     *
     * @return string The censored subject
     */
    private function censorSubject(EmailCache $email)
    {
        ee()->dbg->console_message($email, __METHOD__);
        $subject = $email->subject;

        if (bool_config_item('enable_censoring')) {
            $subject = (string) ee('Format')->make('Text', $subject)->censor();
        }

        return $subject;
    }

    public function email_send($data)
    {
        $settings = ee()->mod_svc->get_settings();
        $str_settings = json_encode(json_decode(json_encode($settings, JSON_PRETTY_PRINT)));
        if (empty($settings['service_order'])) {
            return false;
        }

        ee()->lang->loadfile(EXT_SHORT_NAME);
        ee()->load->library('logger');

        $sent = false;
        $this->email_in = $data;
        unset($data);

        $this->email_out['lookup'] = $this->email_in['lookup'];

        $this->email_in['finalbody'] = $this->email_in['message'];

        $this->email_out['html'] = $this->email_in['html'];

        if (isset($this->email_in['template_content'])) {
            $this->email_out['template_content'] = $this->email_in['template_content'];
        }
        if (count($this->email_in['extras']) > 0) {
            $this->email_out['extras'] = $this->email_in['extras'];
        }

        if ($this->debug == true) {
            ee()->dbg->console_message($this->email_in);
        }

        // Set X-Mailer
        $this->email_out['headers']['X-Mailer'] = APP_NAME.' (via '.EXT_NAME.' '.EXT_VERSION.')';

        // From (may include a name)
        $this->email_out['from'] = array(
            'name' => $this->email_in['from_name'],
            'email' => $this->email_in['from_email'],
        );

        // Reply-To (may include a name)
        if (!empty($this->email_in['headers']['Reply-To'])) {
            $this->email_out['reply-to'] = $this->_name_and_email($this->email_in['headers']['Reply-To']);
        }

        // To (email-only)
        $this->email_out['to'] = array($this->email_in['recipient']);

        // Cc (email-only)
        if (!empty($this->email_in['cc_array'])) {
            $this->email_out['cc'] = array();
            foreach ($this->email_in['cc_array'] as $cc_email) {
                if (!empty($cc_email)) {
                    $this->email_out['cc'][] = $cc_email;
                }
            }
        } elseif (!empty($this->email_in['cc'])) {
            $this->email_out['cc'] = $this->email_in['cc'];
        }

        // Bcc (email-only)
        if (!empty($this->email_in['bcc_array'])) {
            $this->email_out['bcc'] = array();
            foreach ($this->email_in['bcc_array'] as $bcc_email) {
                if (!empty($bcc_email)) {
                    $this->email_out['bcc'][] = $bcc_email;
                }
            }
        } elseif (!empty($this->email_in['headers']['Bcc'])) {
            $this->email_out['bcc'] = $this->_recipient_array($this->email_in['headers']['Bcc']);
        }

        // Subject
        $subject = '';
        if (!empty($this->email_in['subject'])) {
            $subject = $this->email_in['subject'];
        } elseif (!empty($this->email_in['headers']['Subject'])) {
            $subject = $this->email_in['headers']['Subject'];
        }
        $this->email_out['subject'] = (strpos($subject, '?Q?') !== false) ? $this->_decode_q($subject) : $subject;

        // Set HTML/Text and attachments
        // $this->_body_and_attachments();
        ee()->dbg->console_message($this->email_out);
        ee()->dbg->console_message($settings, __METHOD__);
        foreach ($settings['service_order'] as $service) {
            // ee()->dbg->console_message($service, __METHOD__);
            if (!empty($settings[$service.'_active']) && $settings[$service.'_active'] == 'y') {
                $missing_credentials = true;
                ee()->dbg->console_message($service, __METHOD__);
                if (ee()->load->is_loaded('tx_service')) {
                    ee()->dbg->console_message($this->vars, __METHOD__, true);
                    ee()->load->driver('tx_service', array('settings' => $settings));
                    $vars = ee()->tx_service->{$service}->send_email();
                    ee()->dbg->console_message($vars, __METHOD__, true);
                    if ($vars['missing_credentials'] == true) {
                        ee()->logger->developer(sprintf(lang('missing_service_credentials'), $service));
                    }
                    if ($vars['sent'] == false) {
                        ee()->logger->developer(sprintf(lang('could_not_deliver'), $service));
                    } else {
                        ee()->extensions->end_script = true;

                        return true;
                    }
                } else {
                    $message = sprintf(lang('missing_service_class'), $service);
                    ee()->dbg->console_message($message, __METHOD__);
                    ee()->logger->developer($message);
                }
                ee()->dbg->console_message($sent, __METHOD__);
            }
        }

        return false;
    }

    public function _get_service_templates($service)
    {
        try {
            ee()->dbg->console_message($service, __METHOD__);
            ee()->dbg->console_message(ee()->load->is_loaded('tx_service/drivers/'.$service), __METHOD__);
            ee()->load->library('tx_service/drivers/'.$service, array('debug' => $this->debug), $service);
            ee()->dbg->console_message(ee()->load->is_loaded('tx_service/drivers/'.$service), __METHOD__);

            return ee()->{$service}->get_templates();
        } catch (\Throwable $th) {
            //throw $th;
            return $th;
        }
    }

    /**
        Remove the Q encoding from our subject line
     **/
    public function _decode_q($subject)
    {
        $r = '';
        $lines = preg_split('/['.$this->email_crlf.']+/', $subject); // split multi-line subjects
        foreach ($lines as $line) {
            $str = '';
            // $line = str_replace('=9', '', $line); // Replace encoded tabs which ratch the decoding
            $parts = imap_mime_header_decode(trim($line)); // split and decode by charset
            foreach ($parts as $part) {
                $str .= $part->text; // append sub-parts of line together
            }
            $r .= $str; // append to whole subject
        }

        return $r;
        // return utf8_encode($r);
    }

    /**
        Breaks the PITA MIME message we receive into its constituent parts
     **/
    public function _body_and_attachments()
    {
        ee()->dbg->console_message($this->protocol, __METHOD__);
        if ($this->protocol == 'mail') {
            // The 'mail' protocol sets Content-Type in the headers
            if (strpos($this->email_in['header_str'], 'Content-Type: text/plain') !== false) {
                $this->email_out['text'] = $this->email_in['finalbody'];
            } elseif (strpos($this->email_in['header_str'], 'Content-Type: text/html') !== false) {
                $this->email_out['html'] = $this->email_in['finalbody'];
            } else {
                preg_match('/Content-Type: multipart\/[^;]+;\s*boundary="([^"]+)"/i', $this->email_in['header_str'], $matches);
            }
        } else {
            // SMTP and sendmail will set Content-Type in the body
            if (stripos($this->email_in['finalbody'], 'Content-Type: text/plain') === 0) {
                $this->email_out['text'] = $this->_clean_chunk($this->email_in['finalbody']);
            } elseif (stripos($this->email_in['finalbody'], 'Content-Type: text/html') === 0) {
                $this->email_out['html'] = $this->_clean_chunk($this->email_in['finalbody']);
            } else {
                preg_match('/^Content-Type: multipart\/[^;]+;\s*boundary="([^"]+)"/i', $this->email_in['finalbody'], $matches);
            }
        }

        // Extract content and attachments from multipart messages
        if (!empty($matches) && !empty($matches[1])) {
            $boundary = $matches[1];
            $chunks = explode('--'.$boundary, $this->email_in['finalbody']);
            foreach ($chunks as $chunk) {
                if (stristr($chunk, 'Content-Type: text/plain') !== false) {
                    $this->email_out['text'] = $this->_clean_chunk($chunk);
                }

                if (stristr($chunk, 'Content-Type: text/html') !== false) {
                    $this->email_out['html'] = $this->_clean_chunk($chunk);
                }

                // Attachments
                if (stristr($chunk, 'Content-Disposition: attachment') !== false) {
                    preg_match('/Content-Type: (.*?); name=["|\'](.*?)["|\']/is', $chunk, $attachment_matches);
                    if (!empty($attachment_matches)) {
                        if (!empty($attachment_matches[1])) {
                            $type = $attachment_matches[1];
                        }
                        if (!empty($attachment_matches[2])) {
                            $name = $attachment_matches[2];
                        }
                        $attachment = array(
                            'type' => trim($type),
                            'name' => trim($name),
                            'content' => $this->_clean_chunk($chunk),
                        );
                        $this->email_out['attachments'][] = $attachment;
                    }
                }

                if (stristr($chunk, 'Content-Type: multipart') !== false) {
                    // Another multipart chunk - contains the HTML and Text messages, here because we also have attachments
                    preg_match('/Content-Type: multipart\/[^;]+;\s*boundary="([^"]+)"/i', $chunk, $inner_matches);
                    if (!empty($inner_matches) && !empty($inner_matches[1])) {
                        $inner_boundary = $inner_matches[1];
                        $inner_chunks = explode('--'.$inner_boundary, $chunk);
                        foreach ($inner_chunks as $inner_chunk) {
                            if (stristr($inner_chunk, 'Content-Type: text/plain') !== false) {
                                $this->email_out['text'] = $this->_clean_chunk($inner_chunk);
                            }

                            if (stristr($inner_chunk, 'Content-Type: text/html') !== false) {
                                $this->email_out['html'] = $this->_clean_chunk($inner_chunk);
                            }
                        }
                    }
                }
            }
        }

        if (!empty($this->email_out['html'])) {
            // HTML emails will have been run through quoted_printable_encode
            $this->email_out['html'] = quoted_printable_decode($this->email_out['html']);
        }
    }

    /**
        Explodes a comma-delimited string of email addresses into an array
     **/
    public function _recipient_array($recipient_str)
    {
        $recipients = explode(',', $recipient_str);
        $r = array();
        foreach ($recipients as $recipient) {
            $r[] = trim($recipient);
        }

        return $r;
    }

    /**
        Implodes an array of email addresses and names into a comma-delimited string
     **/
    public function _recipient_str($recipient_array, $singular = false)
    {
        if ($singular == true) {
            if (empty($recipient_array['name'])) {
                return $recipient_array['email'];
            } else {
                return $recipient_array['name'].' <'.$recipient_array['email'].'>';
            }
        }
        $r = array();
        foreach ($recipient_array as $k => $recipient) {
            if (!is_array($recipient)) {
                $r[] = $recipient;
            } else {
                if (empty($recipient['name'])) {
                    $r[] = $recipient['email'];
                } else {
                    $r[] = $recipient['name'].' <'.$recipient['email'].'>';
                }
            }
        }

        return implode(',', $r);
    }

    /**
        Removes cruft from a multipart message chunk
     **/
    public function _clean_chunk($chunk)
    {
        return trim(preg_replace('/Content-(Type|ID|Disposition|Transfer-Encoding):.*?'.NL.'/is', '', $chunk));
    }

    /**
        Writes our array of base64-encoded attachments into actual files in the tmp directory
     **/
    public function _write_attachments()
    {
        $r = array();
        ee()->load->helper('file');
        foreach ($this->email_out['attachments'] as $attachment) {
            if (write_file(realpath(sys_get_temp_dir()).'/'.$attachment['name'], base64_decode($attachment['content']))) {
                $r[$attachment['name']] = realpath(sys_get_temp_dir()).'/'.$attachment['name'];
            }
        }

        return $r;
    }

    /**
        Translates a multi-dimensional array into the odd kind of array expected by cURL post
     **/
    public function _http_build_post($arrays, &$new = array(), $prefix = null)
    {
        foreach ($arrays as $key => $value) {
            $k = isset($prefix) ? $prefix.'['.$key.']' : $key;
            if (is_array($value)) {
                $this->_http_build_post($value, $new, $k);
            } else {
                $new[$k] = $value;
            }
        }
    }

    /**
     * View sent emails.
     */
    public static function sent()
    {
        if (!ee()->cp->allowed_group('can_send_cached_email')) {
            show_error(lang('not_allowed_to_email_cache'));
        }

        if (ee()->input->post('bulk_action') == 'remove') {
            ee('Model')->get('EmailCache', ee()->input->get_post('selection'))->all()->delete();
            ee()->view->set_message('success', lang('emails_removed'), '');
        }

        $table = ee('CP/Table', array('sort_col' => 'date', 'sort_dir' => 'desc'));
        $table->setColumns(
            array(
                'subject',
                'date',
                'total_sent',
                'manage' => array(
                    'type' => Table::COL_TOOLBAR,
                ),
                array(
                    'type' => Table::COL_CHECKBOX,
                ),
            )
        );

        $table->setNoResultsText('no_cached_emails', 'create_new_email', ee('CP/URL', EXT_SETTINGS_PATH.'/email/compose'));

        $page = ee()->input->get('page') ? ee()->input->get('page') : 1;
        $page = ($page > 0) ? $page : 1;

        $offset = ($page - 1) * 50; // Offset is 0 indexed

        $count = 0;

        // $emails =ee('Model')->get(EXT_SHORT_NAME.':');
        $emails = ee('Model')->get('EmailCache');

        $search = $table->search;
        if (!empty($search)) {
            $emails = $emails->filterGroup()
                               ->filter('subject', 'LIKE', '%'.$table->search.'%')
                               ->orFilter('message', 'LIKE', '%'.$table->search.'%')
                               ->orFilter('from_name', 'LIKE', '%'.$table->search.'%')
                               ->orFilter('from_email', 'LIKE', '%'.$table->search.'%')
                               ->orFilter('recipient', 'LIKE', '%'.$table->search.'%')
                               ->orFilter('cc', 'LIKE', '%'.$table->search.'%')
                               ->orFilter('bcc', 'LIKE', '%'.$table->search.'%')
                             ->endFilterGroup();
        }

        $count = $emails->count();

        ee()->dbg->console_message($count, __METHOD__);
        $sort_map = array(
            'date' => 'cache_date',
            'subject' => 'subject',
            'status' => 'status',
            'total_sent' => 'total_sent',
        );

        $emails = $emails->order($sort_map[$table->sort_col], $table->sort_dir)
            ->limit(20)
            ->offset($offset)
            ->all();
        // $emails = $emails->all();

        $vars['emails'] = array();
        $data = array();
        foreach ($emails as $email) {
            // Prepare the $email object for use in the modal
            $email->text_fmt = ($email->text_fmt != 'none') ?: 'br'; // Some HTML formatting for plain text
            // $email->subject = htmlentities($this->censorSubject($email), ENT_QUOTES, 'UTF-8');

            $data[] = array(
                $email->subject,
                ee()->localize->human_time($email->cache_date->format('U')),
                $email->total_sent,
                array('toolbar_items' => array(
                    'view' => array(
                        'title' => lang('view_email'),
                        'href' => '',
                        'id' => $email->cache_id,
                        'rel' => 'modal-email-'.$email->cache_id,
                        'class' => 'm-link',
                    ),
                    'sync' => array(
                        'title' => lang('resend'),
                        'href' => ee('CP/URL', EXT_SETTINGS_PATH.'/email/resend/'.$email->cache_id),
                    ), ),
                ),
                array(
                    'name' => 'selection[]',
                    'value' => $email->cache_id,
                    'data' => array(
                        'confirm' => lang('view_email_cache').': <b>'.$email->subject.'(x'.$email->total_sent.')</b>',
                    ),
                ),
            );

            ee()->load->library('typography');
            ee()->typography->initialize(array(
                'bbencode_links' => false,
                'parse_images' => false,
                'parse_smileys' => false,
            ));

            $email->message = ee()->typography->parse_type($email->message, array(
                'text_format' => ($email->text_fmt == 'markdown') ? 'markdown' : 'xhtml',
                'html_format' => 'all',
                'auto_links' => 'n',
                'allow_img_url' => 'y',
            ));

            $vars['emails'][] = $email;
        }

        ee()->dbg->console_message($vars, __METHOD__);
        $table->setData($data);

        $base_url = ee('CP/URL', EXT_SETTINGS_PATH.'/email/sent');
        $vars['table'] = $table->viewData($base_url);

        $vars['pagination'] = ee('CP/Pagination', $count)
            ->currentPage($page)
            ->render($vars['table']['base_url']);

        // Set search results heading
        if (!empty($vars['table']['search'])) {
            ee()->view->cp_heading = sprintf(
                lang('search_results_heading'),
                $vars['table']['total_rows'],
                htmlspecialchars($vars['table']['search'], ENT_QUOTES, 'UTF-8')
            );
        }

        $vars['cp_page_title'] = lang('view_email_cache');
        ee()->javascript->set_global('lang.remove_confirm', lang('view_email_cache').': <b>### '.lang('emails').'</b>');

        $vars['base_url'] = $base_url;
        $vars['cp_page_title'] = lang('view_email_cache');
        ee()->javascript->set_global('lang.remove_confirm', lang('view_email_cache').': <b>### '.lang('emails').'</b>');
        $vars['current_service'] = __FUNCTION__;

        ee()->dbg->console_message($vars, __METHOD__);

        return $vars;
    }

    /**
     * View templates.
     */
    public function view_templates($service_name = null)
    {
        $service_name = is_null($service_name) ? $this->init_service : $service_name['service'];
        ee()->dbg->console_message($service_name, __METHOD__);
        if (ee()->input->post('bulk_action') == 'remove') {
            foreach (ee()->input->get_post('selection') as $slug) {
                $selection = str_replace('_', ' ', $slug);
                $return = $this->delete_template($selection);
            }
            ee()->view->set_message('success', lang('templates_removed'), '');
            ee()->functions->redirect(ee('CP/URL', EXT_SETTINGS_PATH.'/email/view_templates/'));
        }

        $table = ee('CP/Table', array('fieldname' => 'templates'));
        $table->setColumns(
            array(
                'name',
                'created_at',
                'manage' => array(
                    'type' => Table::COL_TOOLBAR,
                ),
                array(
                    'type' => Table::COL_CHECKBOX,
                ),
            )
        );

        $table->setNoResultsText('no_cached_templates', 'create_new_template', ee('CP/URL', EXT_SETTINGS_PATH.'/email/edit_template'));

        $page = ee()->input->get('page') ? ee()->input->get('page') : 1;
        $page = ($page > 0) ? $page : 1;

        $offset = ($page - 1) * 50; // Offset is 0 indexed
        ee()->dbg->console_message($this->init_service, __METHOD__);
        $templates = $this->_get_service_templates($this->init_service);
        ee()->dbg->console_message($templates, __METHOD__);
        $data = array();
        if (!isset($templates['status'])) {
            foreach ($templates as $template) {
                $template = json_decode(json_encode($template), true);
                $data[] = array(
                $template['name'],
                $template['created_at'],
                array(
                    'toolbar_items' => array(
                        'view' => array(
                            'title' => lang('view_template'),
                            'href' => '',
                            'id' => $template['slug'],
                            'rel' => 'modal-template-'.$template['slug'],
                            'class' => 'm-link',
                        ),
                        'edit' => array(
                            'title' => lang('edit_template'),
                            'href' => ee('CP/URL', EXT_SETTINGS_PATH.'/email/edit_template/'.$template['name']),
                        ),
                    ),
                ),
                array(
                    'name' => 'selection[]',
                    'value' => $template['slug'],
                    'data' => array(
                        'confirm' => lang('view_template_cache').': <b>'.$template['subject'].'</b>',
                    ),
                ),
            );

                $vars['templates'][] = $template;
            }
        }

        //ee()->dbg->console_message($vars, __METHOD__);
        $table->setData($data);
        $count = 1;
        $base_url = ee('CP/URL', EXT_SETTINGS_PATH.'/email/view_templates');
        $vars['table'] = $table->viewData($base_url);

        $vars['pagination'] = ee('CP/Pagination', $count)
            ->currentPage($page)
            ->render($vars['table']['base_url']);

        // Set search results heading
        if (!empty($vars['table']['search'])) {
            ee()->view->cp_heading = sprintf(
                lang('search_results_heading'),
                $vars['table']['total_rows'],
                htmlspecialchars($vars['table']['search'], ENT_QUOTES, 'UTF-8')
            );
        }

        $vars['cp_page_title'] = sprintf(lang('view_template_cache'), (is_null($service_name) ? '' : ucfirst($service_name)));
        ee()->javascript->set_global('lang.remove_confirm', lang('view_template_cache').': <b>### '.lang('templates').'</b>');

        // ee()->cp->add_js_script(array( 'file' => array('cp/confirm_remove'),));
        $vars['base_url'] = $base_url;
        ee()->javascript->set_global('lang.remove_confirm', lang('view_template_cache').': <b>### '.lang('templates').'</b>');
        $vars['current_service'] = __FUNCTION__;
        ee()->dbg->console_message($vars, __METHOD__);

        return $vars;
    }

    public function delete_template($template_name)
    {
        $data = array(
            'name' => $template_name,
            'key' => $this->_get_mandrill_api(),
        );
        $api_endpoint = 'https://mandrillapp.com/api/1.0/templates/delete.json';
        $request = '\n'.$api_endpoint.' '.json_encode($data);
        $result = $this->curl_request($api_endpoint, $this->headers, $data, true);
        ee()->logger->developer(ee()->session->userdata('screen_name').' deleted template: '.$template_name.$request);

        return $result;
    }

    /**
     * Check for recipients.
     *
     * An internal validation function for callbacks
     *
     * @param	string
     *
     * @return bool
     */
    public function _check_for_recipients($str)
    {
        ee()->dbg->console_message($str, __METHOD__);
        if (!$str && ee()->input->post('total_gl_recipients') < 1) {
            ee()->form_validation->set_message('_check_for_recipients', lang('required'));

            return false;
        }

        return true;
    }

    /**
     * Attachment Handler.
     *
     * Used to manage and validate attachments. Must remain public,
     * it's a form validation callback.
     *
     * @return bool
     */
    public function _attachment_handler()
    {
        // File Attachments?
        if (!isset($_FILES['attachment']['name']) or empty($_FILES['attachment']['name'])) {
            return true;
        }

        ee()->load->library('upload');
        ee()->upload->initialize(array(
            'allowed_types' => '*',
            'use_temp_dir' => true,
        ));

        if (!ee()->upload->do_upload('attachment')) {
            ee()->form_validation->set_message('_attachment_handler', lang('attachment_problem'));

            return false;
        }

        $data = ee()->upload->data();

        $this->attachments[] = $data['full_path'];

        return true;
    }

    /**
     * Delete Attachments.
     */
    private function deleteAttachments($email)
    {
        ee()->dbg->console_message($email, __METHOD__);
        foreach ($email->attachments as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $email->attachments = array();
        $email->save();
    }
}
// END CLASS
// EOF
