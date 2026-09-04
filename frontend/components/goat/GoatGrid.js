import GoatCard from './GoatCard';

const COLUMN_CLASSES = {
  2: 'row-cols-1 row-cols-sm-2',
  3: 'row-cols-2 row-cols-md-2 row-cols-lg-3',
  4: 'row-cols-2 row-cols-md-3 row-cols-xl-4',
};

export default function GoatGrid({ goats = [], columns = 4, emptyMessage, fillRows = false }) {
  if (!goats.length) {
    return (
      <div className="empty">
        <i className="bi bi-search empty__icon" aria-hidden="true" />
        <h2>No goats match that</h2>
        <p className="mb-0">{emptyMessage || 'Try widening your filters, or clear them to see everything.'}</p>
      </div>
    );
  }

  // A curated strip looks broken when its last row is nearly empty: five
  // featured goats in a four-wide grid strands one card beside three blank
  // slots, which reads as a hole in the page rather than the end of a list.
  // Trimming back to whole rows closes it. Only the homepage passes this --
  // on a catalogue, dropping the remainder would hide somebody's goat.
  const visible = fillRows && goats.length >= columns
    ? goats.slice(0, goats.length - (goats.length % columns))
    : goats;

  return (
    <div className={`row g-3 g-lg-4 ${COLUMN_CLASSES[columns] || COLUMN_CLASSES[4]}`}>
      {visible.map((goat, index) => (
        <div className="col" key={goat.id}>
          <GoatCard goat={goat} index={index} />
        </div>
      ))}
    </div>
  );
}
