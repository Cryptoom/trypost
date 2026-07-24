<?php

declare(strict_types=1);

return [
    'title' => 'Primeiros passos',
    'welcome' => 'Boas-vindas ao TryPost, :name',
    'description' => 'Siga os passos abaixo pra ver como o TryPost funciona e publicar seu primeiro post.',
    'skip' => 'Pular por agora',
    'continue' => 'Continuar no TryPost',
    'progress' => ':done de :total concluídos',
    'progress_label' => 'Progresso',
    'next_step' => 'Próximo',
    'step_label' => 'Passo :number',
    'status' => [
        'complete' => 'Concluído',
        'todo' => 'Pendente',
    ],
    'mcp' => [
        'title' => 'Conecte seu assistente de IA',
        'description' => 'Adicione o TryPost como servidor MCP para o assistente criar e gerenciar posts por você.',
        'copy_step' => 'Copie a URL do servidor TryPost',
        'open_step' => 'Abra seu assistente de IA',
        'open_hint' => 'Escolha um. A página de configurações abre em uma nova aba.',
        'url_label' => 'URL do servidor MCP',
        'copy' => 'Copiar URL',
        'copied' => 'URL do MCP copiada.',
        'guide' => 'Abrir configurações',
        'connect' => 'Conectar com :client',
        'clients' => [
            'claude' => 'Abra Settings → Connectors, adicione um connector customizado e cole a URL acima.',
            'chatgpt' => 'Abra Settings → Apps & Connectors, crie um connector customizado e cole a URL acima.',
        ],
    ],
    'social' => [
        'title' => 'Conecte uma rede social',
        'description' => 'Escolha pelo menos uma rede onde o TryPost possa publicar seu conteúdo.',
        'action' => 'Escolha onde você quer publicar',
        'action_hint' => 'Conecte pelo menos uma conta. Você pode adicionar mais quando quiser.',
    ],
    'first_post' => [
        'title' => 'Crie seu primeiro post',
        'description' => 'Use este prompt no seu assistente, ou crie o post direto no TryPost.',
        'action' => 'Crie um post e veja o TryPost em ação',
        'action_hint' => 'Envie este prompt pro assistente, ou abra o editor do TryPost.',
        'prompt_label' => 'Prompt de exemplo',
        'sample_prompt' => 'Crie um post social amigável apresentando minha marca e adapte para cada rede conectada.',
        'copy_prompt' => 'Copiar prompt',
        'copied' => 'Prompt de exemplo copiado.',
        'create_button' => 'Criar seu primeiro post',
        'or' => 'ou',
    ],
    'ready' => [
        'title' => 'Tudo pronto pra publicar',
        'description' => 'Você já pode seguir. Continue no TryPost e comece a planejar seu conteúdo.',
    ],
];
