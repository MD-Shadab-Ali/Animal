'use client';

import { AnimatePresence, m } from 'motion/react';
import { useState } from 'react';
import { TRANSITION, swipeDirection } from '@/lib/motion';

/** Keeps the first sighting of each photo URL, in order. */
function dedupe(images) {
  const seen = new Set();

  return images.filter((image) => {
    if (!image.url || seen.has(image.url)) return false;

    seen.add(image.url);

    return true;
  });
}

export default function GoatGallery({ goat }) {
  /*
   * `gallery` is the server's answer to which photos this goat actually has,
   * in order and with nothing listed twice -- see GoatResource::gallery().
   *
   * Do not go back to merging `thumbnail` and `images` here. A seller's first
   * upload is copied into goats.thumbnail so the shop grid has something to
   * show, so for seller listings those two overlap and the same photo comes
   * out twice ("1 / 2", both frames identical). Staff listings are the
   * opposite: the thumbnail is uploaded separately and dropping it loses the
   * main photo. The server settles it; this just renders the answer.
   *
   * The fallback is only for a payload cached before `gallery` existed, and
   * applies the same rule so it cannot reintroduce the duplicate.
   */
  const images = goat.gallery ?? dedupe([
    ...(goat.thumbnail ? [{ id: 'main', url: goat.thumbnail, alt: goat.name }] : []),
    ...(goat.images || []),
  ]);

  const [active, setActive] = useState(0);

  if (!images.length) {
    return (
      <div className="gallery__main">
        <div className="card-goat__empty h-100"><i className="bi bi-image" aria-hidden="true" /></div>
      </div>
    );
  }

  const many = images.length > 1;

  /** Wraps, so a swipe past the last photo lands on the first. */
  const show = (index) => setActive(((index % images.length) + images.length) % images.length);

  return (
    <div>
      <div className="gallery__main mb-3">
        {/*
          * The photo a buyer is judging an animal on should change by
          * dissolving, not by being swapped out from under them -- a hard cut
          * between two goats of the same colour reads as a glitch rather than
          * as a second photo. mode="sync" because the two frames have to
          * overlap for that to work at all.
          *
          * Both frames are absolutely positioned inside .gallery__main, which
          * is already position: relative with a fixed 4/3 box, so nothing here
          * can move the page -- including the drag, which the same box clips.
          */}
        <AnimatePresence initial={false} mode="sync">
          <m.img
            key={active}
            className="gallery__frame"
            src={images[active].url}
            alt={images[active].alt || goat.name}
            draggable={false}
            drag={many ? 'x' : false}
            dragConstraints={{ left: 0, right: 0 }}
            dragElastic={0.18}
            dragMomentum={false}
            style={{ touchAction: 'pan-y' }}
            onDragEnd={(event, info) => {
              const move = swipeDirection(info);

              if (move) show(active + move);
            }}
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={TRANSITION.normal}
          />
        </AnimatePresence>

        {many && (
          <span className="gallery__count">{active + 1} / {images.length}</span>
        )}
      </div>

      {many && (
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
