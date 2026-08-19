import MarkdownIt, { type RendererRule } from 'markdown-it';

/**
 * Single shared markdown-it instance for rendering assistant chat replies.
 *
 * This text is LLM output, and the LLM reads post content through its tools —
 * a post body containing something like `<img src=x onerror=...>` can end up
 * echoed back into a reply. `html: false` (markdown-it's default, kept explicit
 * here) makes markdown-it escape raw HTML instead of passing it through, so
 * that payload renders as inert text rather than executing. Do not set
 * `html: true` — the app's XSS posture for this surface depends on it.
 */
export const chatMarkdown = new MarkdownIt({
    html: false,
    linkify: true,
    breaks: true,
});

const defaultLinkOpenRenderer: RendererRule =
    chatMarkdown.renderer.rules.link_open ??
    ((tokens, idx, options, _env, self) => self.renderToken(tokens, idx, options));

chatMarkdown.renderer.rules.link_open = (tokens, idx, options, env, self) => {
    const token = tokens[idx];

    token.attrSet('target', '_blank');
    token.attrSet('rel', 'noopener noreferrer nofollow');

    return defaultLinkOpenRenderer(tokens, idx, options, env, self);
};

export const renderChatMarkdown = (source: string): string => chatMarkdown.render(source);
