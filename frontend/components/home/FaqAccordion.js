export default function FaqAccordion({ items = [], id = 'faq' }) {
  if (!items.length) return null;

  return (
    <div className="accordion mx-auto" id={id} style={{ maxWidth: '48rem' }}>
      {items.map((item, index) => (
        <div className="accordion-item" key={index}>
          <h3 className="accordion-header">
            <button
              className={`accordion-button ${index === 0 ? '' : 'collapsed'}`}
              type="button"
              data-bs-toggle="collapse"
              data-bs-target={`#${id}-${index}`}
              aria-expanded={index === 0}
              aria-controls={`${id}-${index}`}
            >
              {item.question}
            </button>
          </h3>
          <div
            id={`${id}-${index}`}
            className={`accordion-collapse collapse ${index === 0 ? 'show' : ''}`}
            data-bs-parent={`#${id}`}
          >
            <div className="accordion-body prose" dangerouslySetInnerHTML={{ __html: item.answer }} />
          </div>
        </div>
      ))}
    </div>
  );
}
