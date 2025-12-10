# sanitize-svg

a small SVG sanitizer to prevent XSS attacks

## Installation

`npm install @skjnldsv/sanitize-svg`

## Why

Bad actors can put script tags in SVG files.
These script tags are not run when the svg is inside an `<img>`,
but if the SVG file is opened directly ("Open image in...") they will run under the image's domain,
which means that bad actor could steal domain-specific context (cookies, local storage, etc.)

## FAQ
### When should I use this?

Anytime someone tries to upload a svg. 
Optionally on the client, but definitely on the server.

### How do I detect if the SVG is malicious?

The output file will be null

## Usage on Client

```js
import { sanitizeSVG } from '@skjnldsv/sanitize-svg'

// String svg
const svg = '<svg xmlns="http://www.w3.org/2000/svg" version="1.1"><path ...'
const cleanImage = await sanitizeSVG(attachedImage)
if (!cleanImage) {
	alert('Howdy, hacker')
}

// File input onSubmit handler
const onSubmit = async (e) => {
	const attachedImage = e.currentTarget.files[0]
	const cleanImage = await sanitizeSVG(attachedImage)
	if (!cleanImage) {
		alert('Howdy, hacker')
	}
}
```

## License

MIT
