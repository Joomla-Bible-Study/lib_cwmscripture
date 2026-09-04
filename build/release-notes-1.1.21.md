Fixes passages that span chapters, and two Scripture buttons that did nothing.

Asking for a passage that crosses a chapter boundary — Genesis 1:26-2:3, Luke 7:36-8:3, any reference of that shape — failed when the translation was stored on your own site. Instead of the verses you got an error page. References inside a single chapter were never affected, which is why the problem could sit unnoticed on a site for a long time: most scripture links point at one chapter.

The cause was in how the passage query was assembled, not in the scripture text or your translations. Nothing was damaged, and nothing needs repairing — the affected references simply work now.

This release also completes two buttons on Proclaim's Admin Center → Scripture tab that had never been finished. **Remove all translations** and the automatic clean-up that runs when you switch a provider off both stopped with no message at all: the screen simply sat there, because the browser was given an empty reply it could not read. Both now do what they say, and if either ever fails you will see the reason instead of silence.

Two details worth knowing about **Remove all translations**. It removes the translations you downloaded, and it leaves the built-in KJV and WEB in place, so your site always keeps a working Bible. The provider clean-up is narrower than it sounds: switching a provider off only clears catalogue entries you never downloaded. Any translation actually stored on your site stays, because it keeps working offline no matter which provider first offered it.

No action is needed after updating.
