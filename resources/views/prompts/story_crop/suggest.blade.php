{{-- PATCH:story-photo-fit --}}
You are choosing the best vertical 9:16 crop of this photo for an Instagram or Facebook story.
The photo is {{ $width }} x {{ $height }} pixels. Find the 9:16 rectangle that keeps the main
subject (faces, products, key text) fully visible and well framed, and cuts away the least
important parts of the picture.

Return JSON only, with four numbers between 0 and 1, relative to the full photo:
{"x": left edge, "y": top edge, "w": rectangle width, "h": rectangle height}

Rules:
- The rectangle must stay inside the photo: x + w <= 1 and y + h <= 1.
- In pixels the rectangle must be exactly 9:16, so w * {{ $width }} / (h * {{ $height }}) = 0.5625.
- Make the rectangle as large as possible while keeping the subject framed.
- No explanation, no markdown, only the JSON object.
