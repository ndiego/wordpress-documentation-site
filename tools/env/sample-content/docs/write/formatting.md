---
title: Formatting reference
description: Code, tables, keyboard shortcuts and collapsible sections in one page.
type: reference
order: 20
---

# Formatting reference

## Code

```php
<?php
add_filter( 'the_title', function ( $title ) {
	return strtoupper( $title );
} );
```

```js
wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' );
```

Inline code looks like `get_the_title()`.

## Keyboard shortcuts

| Action | Windows | Mac |
|---|---|---|
| Save | <kbd>Ctrl</kbd> + <kbd>S</kbd> | <kbd>⌘</kbd> + <kbd>S</kbd> |
| Undo | <kbd>Ctrl</kbd> + <kbd>Z</kbd> | <kbd>⌘</kbd> + <kbd>Z</kbd> |
| Insert link | <kbd>Ctrl</kbd> + <kbd>K</kbd> | <kbd>⌘</kbd> + <kbd>K</kbd> |

## More

<details>
<summary>Why are there two keys for everything?</summary>

Windows and Linux use <kbd>Ctrl</kbd>; macOS uses <kbd>⌘</kbd>. See [Create a post](create-a-post.md).

</details>
