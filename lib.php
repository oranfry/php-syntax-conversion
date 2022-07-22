<?php

const MATCHING_BRACES = [
    '${' => '}',
    '(' => ')',
    '[' => ']',
    'T_CURLY_OPEN' => '}',
    'T_DOLLAR_OPEN_CURLY_BRACES' => '}',
    '{' => '}',
];

const CONTROL_STRUCTURES = [
    'T_IF' => ['T_ELSEIF', 'T_ELSE', 'T_ENDIF'],
    'T_ELSEIF' => ['T_ELSEIF', 'T_ELSE', 'T_ENDIF'],
    'T_ELSE' => ['T_ENDIF'],
    'T_WHILE' => ['T_ENDWHILE'],
    'T_FOR' => ['T_ENDFOR'],
    'T_FOREACH' => ['T_ENDFOREACH'],
    'T_SWITCH' => ['T_ENDSWITCH'],
];

class signaller
{
    private $handler;
    private $tokens;

    public function __construct(conversion_handler $handler, ?array &$tokens = null)
    {
        $this->handler = $handler;

        if ($tokens !== null) {
            $this->tokens = &$tokens;
        }

        $this->handler->set_signaller($this);
    }

    public function setTokens(?array $tokens)
    {
        $this->tokens = $tokens;
    }

    public function peek(array $exceptions = [], &$index = null)
    {
        for (
            $i = 0;
            ($token = @$this->tokens[$i]) && in_array($token->getTokenName(), $exceptions);
            $i++
        );

        $index = $i;

        return $token;
    }

    private function handle_control($name)
    {
        if ($this->handler instanceof enter_control_listener) {
            $message = $this->handler->enter_control($this->tokens, $name);
        }

        $this->handler->handle_tokens($this->tokens);

        $expect_control_details = $name !== 'T_ELSE';

        while ($peek = @$this->tokens[0]) {
            if ($expect_control_details && $peek->getTokenName() === '(') {
                $this->convert_r('(', [')']);

                $this->handler->handle_tokens($this->tokens);

                if ($this->handler instanceof left_context_listener) {
                    $this->handler->left_context($this->tokens);
                }

                $expect_control_details = false;

                continue;
            }

            if (in_array($peek->getTokenName(), ['T_WHITESPACE', 'T_COMMENT'])) {
                $this->handler->handle_tokens($this->tokens);

                continue;
            }

            // We have found the beginning of the "body" of the control structure

            $daisychain = null;

            if ($peek->getTokenName() == ':') {
                if ($this->handler instanceof enter_control_body_listener) {
                    $body_message = $this->handler->enter_control_body($this->tokens, $name);
                }

                $closed_by = $this->convert_r(':', CONTROL_STRUCTURES[$name]);

                if (in_array($closed_by, ['T_ELSEIF', 'T_ELSE'])) {
                    $daisychain = $closed_by;
                } else {
                    $this->handler->handle_tokens($this->tokens);
                }

                if ($this->handler instanceof left_context_listener) {
                    $this->handler->left_context($this->tokens);
                }

                if ($this->handler instanceof leave_control_body_listener) {
                    $this->handler->leave_control_body($this->tokens, $name, $daisychain, $body_message);
                }
            } elseif ($peek->getTokenName() == '{') {
                if ($this->handler instanceof enter_control_body_listener) {
                    $body_message = $this->handler->enter_control_body($this->tokens, $name);
                }

                $this->convert_r('{', ['}']);

                $this->handler->handle_tokens($this->tokens);

                if ($this->handler instanceof left_context_listener) {
                    $this->handler->left_context($this->tokens);
                }

                $peek2 = $this->peek(['T_WHITESPACE', 'T_COMMENT']);

                if ($peek2 && in_array($peek2_name = $peek2->getTokenName(), ['T_ELSEIF', 'T_ELSE'])) {
                    $daisychain = $peek2_name;
                }

                if ($this->handler instanceof leave_control_body_listener) {
                    $this->handler->leave_control_body($this->tokens, $name, $daisychain, $body_message);
                }
            } elseif (in_array($peek->getTokenName(), array_keys(CONTROL_STRUCTURES))) {
                if ($this->handler instanceof enter_control_body_listener) {
                    $body_message = $this->handler->enter_control_body($this->tokens, $name);
                }

                $this->handle_control($peek->getTokenName());

                if ($this->handler instanceof leave_control_body_listener) {
                    $this->handler->leave_control_body($this->tokens, $name, $daisychain, $body_message);
                }
            } elseif (($peek2 = $this->peek(['T_WHITESPACE', 'T_COMMENT'])) && $peek2->getTokenName() !== 'T_CLOSE_TAG') {
                if ($this->handler instanceof enter_control_body_listener) {
                    $body_message = $this->handler->enter_control_body($this->tokens, $name);
                }

                $this->handle_statement();

                $peek2 = $this->peek(['T_WHITESPACE', 'T_COMMENT']);

                if ($peek2 && in_array($peek2_name = $peek2->getTokenName(), ['T_ELSEIF', 'T_ELSE'])) {
                    $daisychain = $peek2_name;
                }

                if ($this->handler instanceof leave_control_body_listener) {
                    $this->handler->leave_control_body($this->tokens, $name, $daisychain, $body_message);
                }
            }

            if ($this->handler instanceof leave_control_listener) {
                $this->handler->leave_control($this->tokens, $name, $daisychain, $message);
            }

            return;
        }
    }

    private function handle_statement()
    {
        $this->convert_r('', [';', 'T_CLOSE_TAG']);

        $this->handler->handle_tokens($this->tokens);
        if ($this->handler instanceof left_context_listener) {
            $this->handler->left_context($this->tokens);
        }
    }

    public function convert()
    {
        $this->convert_r();

        if ($this->handler instanceof left_context_listener) {
            $this->handler->left_context($this->tokens);
        }
    }

    private function convert_r(?string $context_opener = null, ?array $context_closers = null)
    {
        $ternary_level = 0;

        if ($this->handler instanceof enter_context_listener) {
            $message = $this->handler->enter_context($this->tokens, $context_opener, $context_closers);
        }

        if ($context_opener) {
            $this->handler->handle_tokens($this->tokens);
        }

        while ($peek = @$this->tokens[0]) {
            if ($peek->getTokenName() == 'T_CLOSE_TAG' && $this->handler instanceof leave_php_listener) {
                $this->handler->leave_php($this->tokens);
            }

            if (in_array($peek->getTokenName(), ['T_OPEN_TAG', 'T_OPEN_TAG_WITH_ECHO']) && $this->handler instanceof enter_php_listener) {
                $this->handler->enter_php($this->tokens, $peek->getTokenName() == 'T_OPEN_TAG_WITH_ECHO');
            }

            if ($context_closers && in_array($peek->getTokenName(), $context_closers)) {
                // We have found the close of the current context, fall out

                if ($this->handler instanceof leave_context_listener) {
                    $this->handler->leave_context($this->tokens, $context_opener, $peek->getTokenName(), $message);
                }

                return $peek->getTokenName();
            }

            if (in_array($peek->getTokenName(), array_keys(CONTROL_STRUCTURES))) {
                // found a control structure (if, foreach , switch, ...)

                $this->handle_control($peek->getTokenName());

                continue;
            }

            if ($matching_brace = @MATCHING_BRACES[$peek->getTokenName()]) {
                // We have found the beginning of a context

                $this->convert_r($peek->getTokenName(), [$matching_brace]);

                $this->handler->handle_tokens($this->tokens);

                if ($this->handler instanceof left_context_listener) {
                    $this->handler->left_context($this->tokens);
                }

                continue;
            }

            if ($peek->getTokenName() == '?') {
                $ternary_level++;
            }

            if ($peek->getTokenName() == ':' && $ternary_level) {
                $ternary_level--;
            }

            $this->handler->handle_tokens($this->tokens);
        }

        if ($this->handler instanceof leave_context_listener) {
            $this->handler->leave_context($this->tokens, $context_opener, null, $message);
        }

        if ($this->handler instanceof out_of_tokens_listener) {
            $this->handler->out_of_tokens();
        }
    }
}

interface conversion_handler {
    public function handle_tokens(array &$tokens): void;
    public function set_signaller(signaller $signaller): void;
}

interface enter_context_listener {
    public function enter_context(array &$tokens, ?string $context_opener, ?array $context_closers);
}

interface enter_control_body_listener {
    public function enter_control_body(array &$tokens, string $name);
}

interface enter_control_listener {
    public function enter_control(array &$tokens, string $name);
}

interface enter_php_listener {
    public function enter_php(array &$tokens, bool $with_echo): void;
}

interface leave_context_listener {
    public function leave_context(array &$tokens, ?string $context_opener, ?string $context_closer, $message): void;
}

interface leave_control_body_listener {
    public function leave_control_body(array &$tokens, string $name, ?string $daisychain, $message): void;
}

interface leave_control_listener {
    public function leave_control(array &$tokens, string $name, ?string $daisychain, $message): void;
}

interface left_context_listener {
    public function left_context(array &$tokens): void;
}

interface leave_php_listener {
    public function leave_php(array &$tokens): void;
}

interface left_php_listener {
    public function left_php(array &$tokens): void;
}

interface out_of_tokens_listener {
    public function out_of_tokens(): void;
}
