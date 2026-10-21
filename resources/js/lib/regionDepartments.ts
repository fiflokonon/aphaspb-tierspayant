/**
 * A commune as the regions screen knows it.
 *
 * The association's regions follow the departments, so the screen picks
 * communes department by department; the link itself stays per commune
 * (`regionId`), which lets a region still take a department in part.
 */
export type RegionCity = {
    id: number;
    name: string;
    department: string;
    regionId: number | null;
};

export type DepartmentGroup = {
    department: string;
    cities: RegionCity[];
};

/** How much of a department a set of communes holds. */
export type DepartmentSelection = 'all' | 'some' | 'none';

/** One department a region holds, whole or in part. */
export type DepartmentCoverage = {
    department: string;
    held: number;
    total: number;
};

const byFrenchName = (a: string, b: string): number => a.localeCompare(b, 'fr');

/**
 * The communes grouped by department, departments in alphabetical order.
 * Communes keep the order they came in.
 */
export function groupByDepartment(cities: RegionCity[]): DepartmentGroup[] {
    const groups = new Map<string, RegionCity[]>();

    for (const city of cities) {
        groups.set(city.department, [
            ...(groups.get(city.department) ?? []),
            city,
        ]);
    }

    return [...groups.keys()]
        .sort(byFrenchName)
        .map((department) => ({ department, cities: groups.get(department)! }));
}

/** Whether `selected` holds all, some or none of `cities`. */
export function departmentSelection(
    cities: RegionCity[],
    selected: number[],
): DepartmentSelection {
    const held = cities.filter((city) => selected.includes(city.id)).length;

    if (held === 0) {
        return 'none';
    }

    return held === cities.length ? 'all' : 'some';
}

/**
 * The selection after ticking a department's box: a department wholly
 * selected is released, otherwise its missing communes are added.
 */
export function toggleDepartment(
    cities: RegionCity[],
    selected: number[],
): number[] {
    const ids = cities.map((city) => city.id);

    if (departmentSelection(cities, selected) === 'all') {
        return selected.filter((id) => !ids.includes(id));
    }

    return [...selected, ...ids.filter((id) => !selected.includes(id))];
}

/**
 * One department as the region form shows it.
 *
 * `selectable` are the communes the edited region may take — its own and
 * the free ones; `elsewhere` counts, per region, the communes already taken
 * by another region, which the form shows but never offers.
 */
export type DepartmentTile = {
    department: string;
    selectable: RegionCity[];
    total: number;
    elsewhere: { regionId: number; count: number }[];
};

/**
 * Every department, for the region being edited (`editingId`, or null for a
 * new one).
 */
export function departmentTiles(
    cities: RegionCity[],
    editingId: number | null,
): DepartmentTile[] {
    return groupByDepartment(cities).map(({ department, cities: communes }) => {
        const elsewhere = new Map<number, number>();

        for (const city of communes) {
            if (city.regionId !== null && city.regionId !== editingId) {
                elsewhere.set(
                    city.regionId,
                    (elsewhere.get(city.regionId) ?? 0) + 1,
                );
            }
        }

        return {
            department,
            selectable: communes.filter(
                (city) => city.regionId === null || city.regionId === editingId,
            ),
            total: communes.length,
            elsewhere: [...elsewhere].map(([regionId, count]) => ({
                regionId,
                count,
            })),
        };
    });
}

/**
 * The departments `isHeld` picks communes from, with how many of their
 * communes: `held` below `total` marks a department taken in part.
 */
function coverage(
    cities: RegionCity[],
    isHeld: (city: RegionCity) => boolean,
): DepartmentCoverage[] {
    return groupByDepartment(cities)
        .map(({ department, cities: communes }) => ({
            department,
            held: communes.filter(isHeld).length,
            total: communes.length,
        }))
        .filter((entry) => entry.held > 0);
}

/** The departments a region holds, whole or in part. */
export function regionCoverage(
    cities: RegionCity[],
    regionId: number,
): DepartmentCoverage[] {
    return coverage(cities, (city) => city.regionId === regionId);
}

/** The departments a selection of communes reaches, whole or in part. */
export function selectionCoverage(
    cities: RegionCity[],
    selected: number[],
): DepartmentCoverage[] {
    return coverage(cities, (city) => selected.includes(city.id));
}
