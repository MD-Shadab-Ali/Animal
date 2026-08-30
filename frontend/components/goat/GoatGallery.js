'use client';

import { useState } from 'react';

export default function GoatGallery({ goat }) {
  const images = [
    ...(goat.thumbnail ? [{ id: 'main', url: goat.thumbnail, alt: goat.name }] : []),
    ...(goat.images || []),
  ];

  const [active, setActive] = useState(0);

  if (!images.length) {
    return (
      <div className="gallery__main">
        <div className="card-goat__empty h-100"><i className="bi bi-image" aria-hidden="true" /></div>
      </div>
    );
  }

  return (
    <div>
      <div className="gallery__main mb-3">
        <img src={images[active].url} alt={images[active].alt || goat.name} />

        {images.length > 1 && (
          <span className="gallery__count">{active + 1} / {images.length}</span>
        )}
      </div>

      {images.length > 1 && (
        <div className="gallery__strip">
          {images.map((image, index) => (
            <button
              type="button"
              key={image.id ?? index}
              className={`gallery__thumb ${index === active ? 'is-active' : ''}`}
              onClick={() => setActive(index)}
              aria-label={`Show photo ${index + 1} of ${images.length}`}
              aria-pressed={index === active}
            >
              <img src={image.url} alt="" />
            </button>
          ))}
        </div>
      )}

      {goat.video_url && (
        <a href={goat.video_url} target="_blank" rel="noreferrer" className="btn btn-quiet btn-sm mt-3">
          <i className="bi bi-play-circle" aria-hidden="true" /> Watch the video
        </a>
      )}
    </div>
  );
}
