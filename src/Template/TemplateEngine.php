<?php

declare(strict_types=1);

namespace VoidLux\Template;

/**
 * Simple {{VAR}} substitution engine.
 * Pattern from myctobot's TenantAppBuilder::getCommonReplacements().
 */
class TemplateEngine
{
    private array $variables = [];

    public function setVariable(string $name, string $value): self
    {
        $this->variables[$name] = $value;
        return $this;
    }

    public function setVariables(array $vars): self
    {
        foreach ($vars as $name => $value) {
            $this->variables[$name] = (string) $value;
        }
        return $this;
    }

    public function render(string $template): string
    {
        $replacements = [];
        foreach ($this->variables as $name => $value) {
            $replacements['{{' . $name . '}}'] = $value;
        }
        return strtr($template, $replacements);
    }

    public function renderFile(string $templatePath): string
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template not found: {$templatePath}");
        }
        return $this->render(file_get_contents($templatePath));
    }

    public function getVariables(): array
    {
        return $this->variables;
    }
}
