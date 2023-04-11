<?php

function nytech_chatgpt_menu() {
    $items = array();
    $items['chatgpt'] = array(
        'page callback' => 'screen_chatgpt',
        'access callback' => 'user_is_logged_in',
    );
    return $items;
}

function screen_chatgpt() {
    $form = drupal_get_form('nytech_chatgpt_askai_form');
    return drupal_render($form);
}

class NyTechCall {
    function __construct($values = []) {
        $this->values = $values;
        $this->defaults();
        $this->set_prompt();
        $this->set_max_tokens();
        $this->set_temperature();
        $this->call();
    }

    private function set_user() {
        global $user;
        $user = user_load($user->uid);
        if(!empty($user->field_chatgpt_api_key)) {
            $this->key = $user->field_chatgpt_api_key['und'][0]['value'];
        }
    }

    private function set_prompt() {
        $prompt = false;
        if(!empty($this->values['prompt'])) {
            $prompt = $this->values['prompt'];
        }
        $this->prompt = $prompt;
    }

    private function defaults() {
        // Simple setters.
        $this->url = 'https://api.openai.com/v1/completions';
        $this->method = 'POST';
        $this->model = 'text-davinci-003';
        $this->n = 1;

        // Complex setters.
        $this->set_user();
    }

    private function set_max_tokens() {
        $max_tokens = 50;
        if(!empty($this->values['max_tokens'])) {
            $max_tokens = $this->values['max_tokens'];
        }
        $this->max_tokens = $max_tokens;
    }

    private function set_temperature() {
        $value = 0.5;
        if(!empty($this->values['temperature'])) {
            $value = $this->values['temperature'];
        }
        $this->temperature = $value;
    }

    private function call() {
        $this->headers = [
            'Authorization' => 'Bearer ' . $this->key,
            'Content-Type' => 'application/json',
        ];
        $this->data = [
            'prompt' => $this->prompt,
            'max_tokens' => $this->max_tokens,
            'temperature' => $this->temperature,
            'n' => $this->n,
            'model' => $this->model,
            'stop' => '\n',
        ];
        $this->options = [
            'method' => $this->method,
            'data' => json_encode($this->data),
            'headers' => $this->headers,
        ];
        try {
            $this->result = drupal_http_request($this->url, $this->options);
            $this->output = json_decode($this->result->data);

            if(empty($this->output->error)) {
                new NyTechAICreate($this->prompt, $this->output);
            } else {
                new NyTechErrorCreate($this->output->error);
                watchdog('NyTechAICreate', '<pre></' . print_r(['error' => $this->output->error], true), array(), WATCHDOG_ERROR);
            }
        } catch (ErrorException $e) {
            watchdog('NyTechAICreate', '<pre></' . print_r(['error' => $e, 'data' => $entity], true), array(), WATCHDOG_ERROR);
        }
    }
}

class NyTechAICreate {
    function __construct($prompt, $result) {
        global $user;
        $this->prompt = $prompt;
        $this->result = $result;
        $this->user = $user;
        $this->entity_create();
        $this->fields();
        $this->save();
    }

    private function entity_create() {
        $edit = [
            'type' => 'standard',
            'uid' => $this->user->uid,
            'title' => $this->prompt,
            'chat_id' => $this->result->output->id,
            'model' => $this->result->output->model,
            'prompt_tokens' => $this->result->output->usage->prompt_tokens,
            'completion_tokens' => $this->result->output->usage->completion_tokens,
            'total_tokens' => $this->result->output->usage->total_tokens,
        ];
        var_dump($this); exit;
        $entity = entity_create('overlord', $edit);
        $this->entity = $entity;
    }

    private function fields() {
        $entity = $this->entity;
        foreach($this->result->output->choices as $value) {
            $entity->field_choices['und'][]['value'] = $value->text;
        }
        $entity->field_data['und'][0]['value'] = serialize($this->result->output);
        $this->entity = $entity;
    }

    private function save() {
        try {
            $entity = $this->entity;
            entity_save('overlord', $entity);
            $this->entity = $entity;
        } catch (Exception $e) {
            watchdog('NyTechAICreate', '<pre></' . print_r(['error' => $e, 'data' => $entity], true), array(), WATCHDOG_ERROR);
        }
    }

}

class NyTechErrorCreate {
    function __construct($array) {
        global $user;
        $this->user = $user;
        $this->array = $array;
        $this->entity_create();
        $this->fields();
        $this->save();
    }

    private function entity_create() {
        $edit = [
            'type' => 'standard',
            'uid' => $this->user->uid,
            'title' => $this->array->message,
            'error_type' => $this->array->type,
            'code' => $this->array->code,
        ];
        $entity = entity_create('error', $edit);
        $this->entity = $entity;
    }

    private function fields() {
        $entity = $this->entity;
        $entity->field_data['und'][0]['value'] = serialize($this->array);
        $this->entity = $entity;
    }

    private function save() {
        try {
            $entity = $this->entity;
            entity_save('error', $entity);
            $this->entity = $entity;
        } catch (Exception $e) {
            watchdog('NyTechErrorCreate', '<pre></' . print_r(['error' => $e, 'data' => $entity], true), array(), WATCHDOG_ERROR);
        }
    }

}

function nytech_chatgpt_askai_form($form, &$form_state) {
    $form = new NyTechForm('', '');
    $form->set_actions('nytech_chatgpt_askai_form_validate', 'nytech_chatgpt_askai_form_submit');

    $field = new NyTechFormItem('prompt', 'Prompt', 'textarea');
    $field->placeholder('Tell me what the best part of waking up is and use the style of Deadpool.');
    $form->field($field->field());

    $field = new NyTechFormItem('submit', 'Ask', 'submit');
    $field->classes(['pull-right', 'btn-sm']);
    $form->field($field->field());

    return $form->form();
}

function nytech_chatgpt_askai_form_validate($form, &$form_state) {
    $values = $form_state['values'];
}

function nytech_chatgpt_askai_form_submit($form, &$form_state) {
    $values = $form_state['values'];
    $result = new NyTechCall($values);
}
