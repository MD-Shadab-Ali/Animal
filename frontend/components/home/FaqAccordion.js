/**
 * Every panel starts closed.
 *
 * Opening the first one by default gave that question an answer nobody asked
 * for, and pushed the rest of the list down the page -- so the one thing a
 * reader could not see was the range of questions on offer.
 */
export default function FaqAccordion({ items = [], id = 'faq' }) {
  if (!items.length) return null;

  return (
    <div className="accordion mx-auto" id={id} style={{ maxWidth: '48rem' }}>
      {items.map((item, index) => (
        <div className="accordion-item" key={index}>
          <h3 className="accordion-header">
            <button
              className="accordion-button collapsed"
              type="button"
              data-bs-toggle="collapse"
              data-bs-target={`#${id}-${index}`}
              aria-expanded="false"
              aria-controls={`${id}-${index}`}
            >
              {item.question}
            </button>
          </h3>
          <div
            id={`${id}-${index}`}
            className="accordion-collapse collapse"
            data-bs-parent={`#${id}`}
          >
            <div className="accordion-body prose" dangerouslySetInnerHTML={{ __html: item.answer }} />
          </div>
        </div>
      ))}
    </div>
  );
}
