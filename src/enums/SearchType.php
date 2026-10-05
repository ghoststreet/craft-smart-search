<?php

namespace ghoststreet\craftsmartsearch\enums;

enum SearchType : string
{
    case Search = 'search';
    case AiAnswer = 'ai-answer';
    case AiAnswerStream = 'ai-answer-stream';
    case Native = 'native';

    public function isAiAnswer(): bool
    {
        return $this === self::AiAnswer || $this === self::AiAnswerStream;
    }
}
