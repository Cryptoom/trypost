// PATCH:story-motion-settings: the workspace's Story video settings as the Brand settings page receives them.

export type StoryMotionMode = 'fixed' | 'rotate' | 'random';

export interface StoryMotionSettings {
    enabled: boolean;
    instagram: boolean;
    font: string;
    effects: string[];
    mode: StoryMotionMode;
    logo_layer: boolean;
    ai_notice: { enabled: boolean; text: string };
    ending: { enabled: boolean; name: string; line: string };
}

export interface StoryMotionProps {
    available: boolean;
    settings: StoryMotionSettings;
    fonts: string[];
    effects: string[];
    modes: StoryMotionMode[];
    hasLogo: boolean;
    logoUsable: boolean;
}
