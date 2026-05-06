// Shared constants for KasTip extension.
// Imported by background, popup, content scripts.

export const KASTIP_API = 'https://kastip.app/api';
export const KASTIP_BASE = 'https://kastip.app';

export const MIN_TIP_KAS = 0.5;
export const SOMPI_PER_KAS = 100_000_000;

// Token storage key (chrome.storage.local).
export const STORAGE_TOKEN = 'kastip_token';
export const STORAGE_USER = 'kastip_user';

// Selectors that drive DOM injection on X. Centralized so we can hot-fix
// when X DOM changes (which it will).
export const X_SELECTORS = {
  userNameContainer: '[data-testid="User-Name"]',
  replyTextarea: '[data-testid="tweetTextarea_0"]',
  ownProfileSidebar: '[data-testid="AppTabBar_Profile_Link"]',
};
