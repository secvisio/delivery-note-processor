<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HttpClientOptions;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\SystemPrompt;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

class DeliveryNoteAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        // return an instance of Anthropic, OpenAI, Gemini, Ollama, etc...
        // https://docs.neuron-ai.dev/the-basics/ai-provider
        return new OpenAIResponses(
            key: config('neuron.openai_key'),
            model: config('neuron.openai_model'),
            parameters: [], // Add custom params (temperature, logprobs, etc)
            strict_response: false, // Strict structured output
            httpOptions: new HttpClientOptions(timeout: 30),
        );
    }

    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ["You are a smart AI Agent created in order to find delivery note numbers within text parsed by OCR scanner."],
        );
    }

    /**
     * @return ToolInterface[]|ToolkitInterface[]
     */
    protected function tools(): array
    {
        return [];
    }
}
