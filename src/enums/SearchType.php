<?php

namespace ghoststreet\craftsmartsearch\enums;

enum SearchType : string
{
    case Search = 'search';
    case AiAnswer = 'ai-answer';
    case AiAnswerStream = 'ai-answer-stream';

    public function isAiAnswer(): bool
    {
        return $this === self::AiAnswer || $this === self::AiAnswerStream;
    }

    public static function tryFromParam(?string $value): ?self
    {
        return ($value === null || $value === '') ? self::Search : self::tryFrom($value);
    }
}
