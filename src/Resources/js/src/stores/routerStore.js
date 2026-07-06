// @flow

/**
 * Holds the admin Router instance, captured at application start, so
 * components rendered outside the view tree (the assistant window) can
 * navigate to admin views.
 */
class RouterStore {
    router = null;

    setRouter(router) {
        if (!this.router) {
            this.router = router;
        }
    }

    navigate(view, attributes) {
        if (!this.router) {
            return false;
        }

        try {
            // Router.navigate throws synchronously for an unknown route name
            // (e.g. a stale/hallucinated view); report failure instead.
            this.router.navigate(view, attributes);
        } catch (error) {
            return false;
        }

        return true;
    }
}

export default new RouterStore();
