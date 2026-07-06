// @flow

// Tabs the assistant can work on. The form bridge is registered on exactly
// these page-edit tab views, and the backend can build an edit schema for
// each of them.
export const SUPPORTED_TABS = ['content', 'seo'];

/**
 * Router view name of a sibling tab of the given tab view, e.g.
 * tabViewName('sulu_page.page_edit_form.content', 'seo')
 * === 'sulu_page.page_edit_form.seo'.
 */
export function tabViewName(currentViewName, tab) {
    const segments = (currentViewName || '').split('.');
    if (segments.length < 2) {
        return null;
    }
    segments[segments.length - 1] = tab;

    return segments.join('.');
}

/**
 * The supported tabs that actually exist as siblings of the current route.
 * Falls back to just the current tab when the route tree is not available,
 * so the assistant never offers a switch that cannot work.
 */
export function availableTabs(route, currentTab) {
    const children = (route && route.parent && route.parent.children) || [];
    const names = children.map((child) => ((child && child.name) || '').split('.').pop());
    const tabs = SUPPORTED_TABS.filter((tab) => names.includes(tab));

    return tabs.length > 0 ? tabs : [currentTab];
}
