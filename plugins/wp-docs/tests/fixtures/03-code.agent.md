---
title: Fixture 03-code
description: A test fixture.
type: reference
url: "https://wordpress.org/docs/get-started/03-code/"
source: "https://github.com/ndiego/wordpress-documentation/blob/trunk/docs/get-started/03-code.md"
---

# Code

```php
<?php
add_action( 'init', function () {
	register_post_type( 'book', array( 'public' => true ) );
} );
```

```js
const el = document.querySelector( '.a > .b' );
```

```
No language, with <angle brackets> & ampersands.
```

~~~css
.callout { color: red; }
~~~

Block markup samples stay escaped:

```html
<!-- wp:paragraph -->
<p>Hello</p>
<!-- /wp:paragraph -->
```

    indented code block
