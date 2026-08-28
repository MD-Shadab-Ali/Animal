/**
 * Buyer quotes, drifting past on a loop.
 *
 * The track holds the list twice and travels exactly half its width, so the
 * moment it finishes it is showing the same frame it started on and the jump
 * back is invisible. That is the whole trick; there is no JavaScript in it.
 *
 * The second copy is aria-hidden: it is the same quotes, and a screen reader
 * reading every testimonial twice would be worse than not animating at all.
 */
function Quote({ item }) {
  return (
    <figure className="quote mb-0 h-100">
      <div className="quote__stars" aria-label={`${item.rating} out of 5 stars`}>
        {'★'.repeat(item.rating)}{'☆'.repeat(Math.max(0, 5 - item.rating))}
      </div>

      <blockquote className="quote__text">{item.quote}</blockquote>

      <figcaption className="quote__by">
        <span className="avatar">
          {item.avatar ? <img src={item.avatar} alt="" /> : item.name.charAt(0)}
        </span>
        <span>
          <span className="d-block fw-semibold text-ink small">{item.name}</span>
          {item.designation && <span className="d-block small text-soft">{item.designation}</span>}
        </span>
      </figcaption>
    </figure>
  );
}

export default function Testimonials({ items = [] }) {
  if (!items.length) return null;

  // Seconds per card rather than a fixed total, so adding a quote makes the
  // loop longer instead of making every card move faster.
  const duration = `${items.length * 14}s`;

  return (
    <div className="marquee" style={{ '--marquee-duration': duration }}>
      <div className="marquee__track">
        {items.map((item, index) => (
          <div className="marquee__item" key={`a-${index}`}>
            <Quote item={item} />
          </div>
        ))}

        {items.map((item, index) => (
          <div className="marquee__item" key={`b-${index}`} aria-hidden="true">
            <Quote item={item} />
          </div>
        ))}
      </div>
    </div>
  );
}
