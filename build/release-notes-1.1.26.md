Whole chapters and multi-chapter readings now come back complete from GetBible.net.

These problems affected translations your site gets from GetBible.net, rather than ones stored on your own site:

- Asking for a whole chapter, such as Psalm 23, returned only its first verse.
- A reading spanning several chapters, the form CWM LivingWord's reading plans use, also came back as a single verse.
- A passage crossing a chapter boundary, such as John 3:16-4:2, returned nothing.

The cause was GetBible's passage lookup, which only handles verses within one chapter. Whole chapters and passages that cross chapters now come from GetBible's chapter service instead. Passages within one chapter are fetched exactly as before.

Answers cached by the old lookup are no longer used, so a chapter won't keep showing one verse until your scripture cache expires.

Translations stored on your own site, and API.Bible, work as they did before.

No action is needed after updating.
