export default function Testimonials({ items = [] }) {
  if (!items.length) return null;

  return (
    <div className="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 g-lg-4">
      {items.map((item, index) => (
        <div className="col" key={index}>
          <figure className="quote mb-0">
            <div className="quote__stars" aria-label={`${item.rating} out of 5 stars`}>
              {'\u2605'.repeat(item.rating)}{'\u2606'.repeat(Math.max(0, 5 - item.rating))}
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
        </div>
      ))}
    </div>
  );
}
