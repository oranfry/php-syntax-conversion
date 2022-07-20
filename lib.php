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
    }

    public function setTokens(?array $tokens)
    {
        $this->tokens = $tokens;
    }

    private function handle_control($name, $already_started = false)
    {
        $message = $this->handler->enter_control($this->tokens, $name);

        $expect_control_details = $name !== 'T_ELSE';

        while ($peek = @$this->tokens[0]) {
            if (!$already_started && $peek->getTokenName() === $name) {
                $this->handler->handle_tokens($this->tokens);
                $already_started = true;

                continue;
            }

            if ($expect_control_details && $peek->getTokenName() === '(') {
                $this->convert_r('(', [')']);

                $expect_control_details = false;

                continue;
            }

            if (in_array($peek->getTokenName(), ['T_WHITESPACE', 'T_COMMENT'])) {
                $this->handler->handle_tokens($this->tokens);

                continue;
            }

            // We have found the beginning of the "body" of the control structure

            $body_message = $this->handler->enter_control_body($this->tokens, $name);

            $daisychain = null;

            if ($peek->getTokenName() == ':') {
                $closed_by = $this->convert_r(':', CONTROL_STRUCTURES[$name]);

                if (in_array($closed_by, ['T_ELSEIF', 'T_ELSE'])) {
                    $daisychain = $closed_by;
                }
            } elseif ($peek->getTokenName() == '{') {
                $this->convert_r('{', ['}']);

                for ($i = 0, $peek2 = null; ($peek2 = @$this->tokens[$i]) && in_array($peek2->getTokenName(), ['T_WHITESPACE', 'T_COMMENT']); $i++);

                if ($peek2 && in_array($peek2_name = $peek2->getTokenName(), ['T_ELSEIF', 'T_ELSE'])) {
                    $daisychain = $peek2_name;
                }
            } elseif (in_array($peek->getTokenName(), array_keys(CONTROL_STRUCTURES))) {
                $this->handle_control($peek->getTokenName());
            } else {
                $this->handle_statement();

                for ($i = 0, $peek2 = null; ($peek2 = @$this->tokens[$i]) && in_array($peek2->getTokenName(), ['T_WHITESPACE', 'T_COMMENT']); $i++);

                if ($peek2 && in_array($peek2_name = $peek2->getTokenName(), ['T_ELSEIF', 'T_ELSE'])) {
                    $daisychain = $peek2_name;
                }
            }

            $this->handler->leave_control_body($this->tokens, $name, $daisychain, $body_message);
            $this->handler->leave_control($this->tokens, $name, $daisychain, $message);

            return;
        }
    }

    private function handle_statement()
    {
        $this->convert_r('', [';', 'T_CLOSE_TAG']);
    }

    public function convert()
    {
        $this->convert_r();
    }

    private function convert_r(?string $context_opener = null, ?array $context_closers = null)
    {
        $ternary_level = 0;

        $message = $this->handler->enter_context($this->tokens, $context_opener, $context_closers);

        while ($peek = @$this->tokens[0]) {
            if ($context_closers && in_array($peek->getTokenName(), $context_closers)) {
                // We have found the close of the current context, fall out

                $this->handler->leave_context($this->tokens, $context_opener, $peek->getTokenName(), $message);

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

        $this->handler->leave_context($this->tokens, $context_opener, null, $message);
    }
}

interface conversion_handler {
    public function enter_context(array &$tokens, ?string $context_opener, ?array $context_closers);
    public function enter_control_body(array &$tokens, string $name);
    public function enter_control(array &$tokens, string $name);
    public function handle_tokens(array &$tokens): void;
    public function leave_context(array &$tokens, ?string $context_opener, ?string $context_closer, $message): void;
    public function leave_control_body(array &$tokens, string $name, ?string $daisychain, $message): void;
    public function leave_control(array &$tokens, string $name, ?string $daisychain, $message): void;
}